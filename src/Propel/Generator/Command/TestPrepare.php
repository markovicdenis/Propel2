<?php

namespace Propel\Generator\Command;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\Output;

/**
 * @author William Durand <william.durand1@gmail.com>
 */
class TestPrepare extends AbstractCommand
{
    /**
     * @var string
     */
    const FIXTURES_DIR      = 'tests/Fixtures';

    /**
     * @var string
     */
    const DEFAULT_VENDOR    = 'mysql';

    /**
     * @var string
     */
    const DEFAULT_DSN       = 'mysql:host=127.0.0.1;dbname=test';

    /**
     * @var string
     */
    const DEFAULT_DB_USER   = 'root';

    /**
     * @var string
     */
    const DEFAULT_DB_PASSWD = '';

    /**
     * @var array
     */
    protected $fixtures = array(
        'bookstore'             => array('bookstore', 'bookstore-cms', 'bookstore-behavior'),
        'bookstore-packaged'    => array('bookstore-packaged', 'bookstore-log'),
        'namespaced'            => 'bookstore_namespaced',
        'reverse/mysql'         => 'reverse-bookstore',
        'schemas'               => 'bookstore',
    );

    /**
     * @var string
     */
    protected $root = null;

    public function __construct()
    {
        parent::__construct();

        $this->root      = realpath(__DIR__.'/../../../../');
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDefinition(array(
                new InputOption('vendor',       null, InputOption::VALUE_REQUIRED, 'The database vendor', self::DEFAULT_VENDOR),
                new InputOption('dsn',          null, InputOption::VALUE_OPTIONAL, 'The data source name', self::DEFAULT_DSN),
                new InputOption('user',          'u', InputOption::VALUE_REQUIRED, 'The database user', self::DEFAULT_DB_USER),
                new InputOption('password',      'p', InputOption::VALUE_REQUIRED, 'The database password', self::DEFAULT_DB_PASSWD),
            ))
            ->setName('test:prepare')
            ->setDescription('Prepare the Propel test suite by building fixtures')
            ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        foreach ($this->fixtures as $fixturesDir => $database) {
            $this->buildFixtures(sprintf('%s/%s', self::FIXTURES_DIR, $fixturesDir), $database, $input, $output);
        }
    }

    /**
     * @param string $fixturesDir
     */
    protected function buildFixtures($fixturesDir, $database, InputInterface $input, OutputInterface $output)
    {
        if (!file_exists($fixturesDir)) {
            $output->writeln(sprintf('<error>Directory "%s" not found.</error>', $fixturesDir));

            return;
        }

        $output->writeln(sprintf('Building fixtures in <info>%-40s</info> ', $fixturesDir));

        chdir($fixturesDir);

        $distributionFiles = array(
            'build.properties.dist' => 'build.properties',
            'runtime-conf.xml.dist' => 'runtime-conf.xml',
        );

        foreach ($distributionFiles as $sourceFile => $targetFile) {
            if (is_file($sourceFile)) {
                $content = file_get_contents($sourceFile);

                $content = str_replace('##DATABASE_VENDOR##',   $input->getOption('vendor'), $content);
                $content = str_replace('##DATABASE_URL##',      $input->getOption('dsn'), $content);
                $content = str_replace('##DATABASE_USER##',     $input->getOption('user'), $content);
                $content = str_replace('##DATABASE_PASSWORD##', $input->getOption('password'), $content);

                file_put_contents($targetFile, $content);
            } else {
                $output->writeln(sprintf('<error>No "%s" file found, skipped.</error>', $sourceFile));

                return;
            }
        }

        if (0 < count((array) $this->getSchemas('.')) || false === strstr($fixturesDir, 'reverse')) {
            $in = new ArrayInput(array(
                'command'	    => 'sql:build',
                '--input-dir'   => '.',
                '--output-dir'  => 'build/sql/',
                '--platform'    => ucfirst($input->getOption('vendor')) . 'Platform',
                '--verbose'		=> $input->getOption('verbose'),
            ));

            $command = $this->getApplication()->find('sql:build');
            $command->run($in, $output);

            $connections = array();
            if (is_array($database)) {
                foreach ($database as $db) {
                    $connections[] = sprintf(
                        '%s=%s;username=%s;password=%s',
                        $db, $input->getOption('dsn'),
                        $input->getOption('user'), $input->getOption('password')
                    );
                }
            } else {
                $connections[] = sprintf(
                    '%s=%s;username=%s;password=%s',
                    $database, $input->getOption('dsn'),
                    $input->getOption('user'), $input->getOption('password')
                );
            }

            $in = new ArrayInput(array(
                'command'       => 'sql:insert',
                '--output-dir'  => 'build/sql/',
                '--connection'  => $connections,
                '--verbose'		=> $input->getOption('verbose'),
            ));

            $command = $this->getApplication()->find('sql:insert');
            $command->run($in, $output);
        }

        if (0 < count((array) $this->getSchemas('.'))) {
            $in = new ArrayInput(array(
                'command'       => 'config:build',
                '--input-dir'   => '.',
                '--output-file' => sprintf('build/conf/%s-conf.php', is_array($database) ? $database[0] : $database),
                '--verbose'     => $input->getOption('verbose'),
            ));

            $command = $this->getApplication()->find('config:build');
            $command->run($in, $output);

            $in = new ArrayInput(array(
                'command'       => 'model:build',
                '--input-dir'   => '.',
                '--output-dir'  => 'build/classes/',
                '--platform'    => ucfirst($input->getOption('vendor')) . 'Platform',
                '--verbose'		=> $input->getOption('verbose'),
            ));

            $command = $this->getApplication()->find('model:build');
            $command->run($in, $output);
        }

        chdir($this->root);
    }
}
