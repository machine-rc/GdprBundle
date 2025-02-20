<?php

namespace SpecShaper\GdprBundle\Command;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Column;
use SpecShaper\GdprBundle\Model\PersonalData;
use SpecShaper\GdprBundle\Types\PersonalDataType;
use SpecShaper\GdprBundle\Utils\Disposer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dispose Command
 *
 * A command to visit each PersonalData field in every database table and dispose of any data which has expired.
 *
 * terminal command = php bin/console gdpr:dispose
 *
 * @author Mark Ogilvie <mark.ogilvie@ogilvieconsulting.net>
 */
class DisposeCommand extends Command
{
    /** @var EntityManagerInterface */
    private $em;

    /** @var AnnotationReader */
    private $reader;

    /** @var Connection */
    private $connection;

    /** @var array  */
    private $personalDataFields = [];

    /** @var Comparator */
    private $comparator;

    /** @var Disposer */
    private $disposer;

    protected function configure()
    {
        $this
            ->setName('gdpr:dispose')
            ->setDescription('Command to dispose of expired data in a personal_data field.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->em = $this->getContainer()->get('doctrine')->getManager();
        $this->reader = $this->getContainer()->get('annotation_reader');
        $this->connection = $this->getContainer()->get('doctrine.dbal.default_connection');
        $this->comparator = new Comparator();
        $this->disposer = new Disposer();

        // Populate the array with the entities and fields that use the personal_data column type.
        $this->getPersonalDataFields();

        // Filter the personal_data fields for those that have expired
        $this->replacePersonalData($output);

    }

    /**
     * Get PersonalData fields.
     *
     * Visit every entity and identify fields that contain a personal_data annotation.
     * Store the field to an array for processing.
     *
     * @return array
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function getPersonalDataFields()
    {

        $managedEntities = $this->em->getMetadataFactory()->getAllMetadata();

        /** @var ClassMetadata $managedEntity */
        foreach ($managedEntities as $managedEntity) {

            // Ignore mapped superclass entities.
            if (property_exists($managedEntity, 'isMappedSuperclass') && $managedEntity->isMappedSuperclass === true) {
                continue;
            }

            $entityClass = $managedEntity->getName();

            $reflectionProperites = $managedEntity->getReflectionProperties();

            /** @var \ReflectionProperty $refProperty */
            foreach ($reflectionProperites as $refProperty) {

                foreach ($this->reader->getPropertyAnnotations($refProperty) as $key => $annotation) {

                    // Skip any anotation that is not a Column type.
                    if (!$annotation instanceof Column) {
                        continue;
                    }

                    // Ignore any column that is not of a personal_data type.
                    if ($annotation->type !== PersonalDataType::NAME) {
                        continue;
                    }

                    // @todo throw an error if foreign keys or primary keys are attached?

                    // Get the table column name.
                    $columnName = $managedEntity->getColumnName($refProperty->getName());

                    // Store the field data information for later use.
                    $this->personalDataFields[$entityClass][$refProperty->getName()] = [
                        'tableName' => $managedEntity->getTableName(),
                        'identifier' => $managedEntity->getSingleIdentifierColumnName(),
                        'columnName' => $columnName,
                        'refProperty' => $refProperty,
                        'annotation' => $annotation,
                    ];
                }
            }
        }

        return $this->personalDataFields;
    }

    /**
     * Replace personal data with annonymised data if the retention period has been passed.
     *
     * @todo Work in progress
     * @param OutputInterface $output
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function replacePersonalData( OutputInterface $output)
    {
        $output->writeln('Reload personal data to original columns');

        // Get the query builder to load existing entity data.
        $queryBuilder = $this->connection->createQueryBuilder();

        $progressBar = new ProgressBar($output, count($this->getPersonalDataFields()));
        $progressBar->start();

        $today = new \DateTime();

        // Loop through all of the personal data fields in all entities.
        foreach ($this->getPersonalDataFields() as $entityClass => $field) {

            // Get the name of the entity table in the database.
            $tableName = $this->em->getClassMetadata($entityClass)->getTableName();

            // Loop through each personal_data field in the entity.
            foreach ($field as $propertyArray) {

                // Get all data for the current entity and field.
                $queryBuilder
                    ->select('t.'. $propertyArray['identifier'] .', t.'.$propertyArray['columnName'].' AS personalData')
                    ->from($propertyArray['tableName'], 't');

                $results = $queryBuilder->execute();

                // For each selected entity field create a personal data object and save it to the temporary field.
                foreach ($results as $result) {

                    // Get the copied personal_data from the query.
                    /** @var PersonalData $personalData */
                    $personalData = unserialize($result['personalData']);

                    if($personalData instanceof PersonalData) {

                        if ($personalData->getCreatedOn() instanceof \DateTime) {

                            // Get the date that the personal data was created and clone it.
                            $keepUntil = clone $personalData->getCreatedOn();

                            // Modify the date by adding the date interval that it is to be retained for.
                            $keepUntil->add($personalData->getRetainFor());

                            // If the modified date is still less than current date, the dispose of the data.
                            if ($keepUntil < $today) {
                                $this->disposer->disposeByPersonalData($personalData);

                                // Update the database with the disposed data.
                                $this->connection->update(
                                    $tableName,
                                    array(
                                        $propertyArray['columnName'] => $personalData
                                    ),
                                    array($propertyArray['identifier'] => $result[$propertyArray['identifier']])
                                );
                            }
                            unset($keepUntil);
                        }
                    }
                }
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $output->writeln(".");
    }
}
