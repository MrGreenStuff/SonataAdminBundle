<?php

/*
 * This file is part of the Sonata package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Command;

use Sensio\Bundle\GeneratorBundle\Command\Helper\QuestionHelper;
use Sonata\AdminBundle\Generator\AdminGenerator;
use Sonata\AdminBundle\Generator\ControllerGenerator;
use Sonata\AdminBundle\Manipulator\ServicesManipulator;
use Sonata\AdminBundle\Model\ModelManagerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @author Marek Stipek <mario.dweller@seznam.cz>
 * @author Simon Cosandey <simon.cosandey@simseo.ch>
 */
class GenerateAdminCommand extends ContainerAwareCommand
{
    /** @var string[] */
    private $managerTypes;

    /**
     * {@inheritDoc}
     */
    public function configure()
    {
        $this
            ->setName('sonata:admin:generate')
            ->setDescription('Generates an admin class based on the given model class')
            ->addArgument('model', InputArgument::REQUIRED, 'The fully qualified model class')
            ->addOption('bundle', 'b', InputOption::VALUE_OPTIONAL, 'The bundle name')
            ->addOption('admin', 'a', InputOption::VALUE_OPTIONAL, 'The admin class basename')
            ->addOption('controller', 'c', InputOption::VALUE_OPTIONAL, 'The controller class basename')
            ->addOption('manager', 'm', InputOption::VALUE_OPTIONAL, 'The model manager type')
            ->addOption('services', 'y', InputOption::VALUE_OPTIONAL, 'The services YAML file', 'services.yml')
            ->addOption('id', 'i', InputOption::VALUE_OPTIONAL, 'The admin service ID')
        ;
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $modelClass = Validators::validateClass($input->getArgument('model'));
        $modelClassBasename = current(array_slice(explode('\\', $modelClass), -1));
        $bundle = $this->getBundle($input->getOption('bundle') ?: $this->getBundleNameFromClass($modelClass));
        $adminClassBasename = $input->getOption('admin') ?: $modelClassBasename . 'Admin';
        $adminClassBasename = Validators::validateAdminClassBasename($adminClassBasename);
        $managerType = $input->getOption('manager') ?: $this->getDefaultManagerType();
        $modelManager = $this->getModelManager($managerType);
        $skeletonDirectory = __DIR__ . '/../Resources/skeleton';
        $adminGenerator = new AdminGenerator($modelManager, $skeletonDirectory);

        try {
            $adminGenerator->generate($bundle, $adminClassBasename, $modelClass);
            $output->writeln(sprintf(
                '%sThe admin class "<info>%s</info>" has been generated under the file "<info>%s</info>".',
                PHP_EOL,
                $adminGenerator->getClass(),
                realpath($adminGenerator->getFile())
            ));
        } catch (\Exception $e) {
            $this->writeError($output, $e->getMessage());
        }

        if ($controllerClassBasename = $input->getOption('controller')) {
            $controllerClassBasename = Validators::validateControllerClassBasename($controllerClassBasename);
            $controllerGenerator = new ControllerGenerator($skeletonDirectory);

            try {
                $controllerGenerator->generate($bundle, $controllerClassBasename);
                $output->writeln(sprintf(
                    '%sThe controller class "<info>%s</info>" has been generated under the file "<info>%s</info>".',
                    PHP_EOL,
                    $controllerGenerator->getClass(),
                    realpath($controllerGenerator->getFile())
                ));
            } catch (\Exception $e) {
                $this->writeError($output, $e->getMessage());
            }
        }

        if ($servicesFile = $input->getOption('services')) {
            $adminClass = $adminGenerator->getClass();
            $file = sprintf('%s/Resources/config/%s', $bundle->getPath(), $servicesFile);
            $servicesManipulator = new ServicesManipulator($file);
            $controllerName = $controllerClassBasename
                ? sprintf('%s:%s', $bundle->getName(), substr($controllerClassBasename, 0, -10))
                : 'SonataAdminBundle:CRUD'
            ;

            try {
                $id = $input->getOption('id') ?: $this->getAdminServiceId($bundle->getName(), $adminClassBasename);
                $servicesManipulator->addResource($id, $modelClass, $adminClass, $controllerName, $managerType);
                $output->writeln(sprintf(
                    '%sThe service "<info>%s</info>" has been appended to the file <info>"%s</info>".',
                    PHP_EOL,
                    $id,
                    realpath($file)
                ));
            } catch (\Exception $e) {
                $this->writeError($output, $e->getMessage());
            }
        }

        return 0;
    }

    /**
     * {@inheritDoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $question = $this->getQuestionHelper();
        $question->writeSection($output, 'Welcome to the Sonata admin generator');
        $modelClass = $this->askAndValidate(
            $output,
            'The fully qualified model class',
            $input->getArgument('model'),
            'Sonata\AdminBundle\Command\Validators::validateClass'
        );
        $modelClassBasename = current(array_slice(explode('\\', $modelClass), -1));
        $bundleName = $this->askAndValidate(
            $output,
            'The bundle name',
            $input->getOption('bundle') ?: $this->getBundleNameFromClass($modelClass),
            'Sensio\Bundle\GeneratorBundle\Command\Validators::validateBundleName'
        );
        $adminClassBasename = $this->askAndValidate(
            $output,
            'The admin class basename',
            $input->getOption('admin') ?: $modelClassBasename . 'Admin',
            'Sonata\AdminBundle\Command\Validators::validateAdminClassBasename'
        );

        if (count($this->getAvailableManagerTypes()) > 1) {
            $managerType = $this->askAndValidate(
                $output,
                'The manager type',
                $input->getOption('manager') ?: $this->getDefaultManagerType(),
                array($this, 'validateManagerType')
            );
            $input->setOption('manager', $managerType);
        }

        $question = $question->getQuestion('Do you want to generate a controller', 'no', '?');

        if ($question->askConfirmation($output, $question, false)) {
            $controllerClassBasename = $this->askAndValidate(
                $output,
                'The controller class basename',
                $input->getOption('controller') ?: $modelClassBasename . 'AdminController',
                'Sonata\AdminBundle\Command\Validators::validateControllerClassBasename'
            );
            $input->setOption('controller', $controllerClassBasename);
        }

        $question = $question->getQuestion('Do you want to update the services YAML configuration file', 'yes', '?');

        if ($question->askConfirmation($output, $question)) {
            $path = $this->getBundle($bundleName)->getPath() . '/Resources/config/';
            $servicesFile = $this->askAndValidate(
                $output,
                'The services YAML configuration file',
                is_file($path . 'admin.yml') ? 'admin.yml' : 'services.yml',
                'Sonata\AdminBundle\Command\Validators::validateServicesFile'
            );
            $id = $this->askAndValidate(
                $output,
                'The admin service ID',
                $this->getAdminServiceId($bundleName, $adminClassBasename),
                'Sonata\AdminBundle\Command\Validators::validateServiceId'
            );
            $input->setOption('services', $servicesFile);
            $input->setOption('id', $id);
        }

        $input->setArgument('model', $modelClass);
        $input->setOption('admin', $adminClassBasename);
        $input->setOption('bundle', $bundleName);
    }

    /**
     * @param string $managerType
     * @return string
     * @throws \InvalidArgumentException
     */
    public function validateManagerType($managerType)
    {
        $managerTypes = $this->getAvailableManagerTypes();

        if (!isset($managerTypes[$managerType])) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid manager type "%s". Available manager types are "%s".',
                $managerType,
                implode('", "', $managerTypes)
            ));
        }

        return $managerType;
    }

    /**
     * @param string $class
     * @return string|null
     * @throws \InvalidArgumentException
     */
    private function getBundleNameFromClass($class)
    {
        $application = $this->getApplication();
        /* @var $application Application */

        foreach ($application->getKernel()->getBundles() as $bundle) {
            if (strpos($class, $bundle->getNamespace() . '\\') === 0) {
                return $bundle->getName();
            };
        }

        return null;
    }

    /**
     * @param string $name
     * @return BundleInterface
     */
    private function getBundle($name)
    {
        return $this->getKernel()->getBundle($name);
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     */
    private function writeError(OutputInterface $output, $message)
    {
        $output->writeln(sprintf("\n<error>%s</error>", $message));
    }

    /**
     * @param OutputInterface $output
     * @param string $question
     * @param mixed $default
     * @param callable $validator
     * @return mixed
     */
    private function askAndValidate(OutputInterface $output, $question, $default, $validator)
    {
        $question = $this->getQuestionHelper();

        return $question->askAndValidate($output, $question->getQuestion($question, $default), $validator, false, $default);
    }

    /**
     * @return string
     * @throws \RuntimeException
     */
    private function getDefaultManagerType()
    {
        if (!$managerTypes = $this->getAvailableManagerTypes()) {
            throw new \RuntimeException('There are no model managers registered.');
        }

        return current($managerTypes);
    }

    /**
     * @param string $managerType
     * @return ModelManagerInterface
     */
    private function getModelManager($managerType)
    {
        return $this->getContainer()->get('sonata.admin.manager.' . $managerType);
    }

    /**
     * @param string $bundleName
     * @param string $adminClassBasename
     * @return string
     */
    private function getAdminServiceId($bundleName, $adminClassBasename)
    {
        $prefix = substr($bundleName, -6) == 'Bundle' ? substr($bundleName, 0, -6) : $bundleName;
        $suffix = substr($adminClassBasename, -5) == 'Admin' ? substr($adminClassBasename, 0, -5) : $adminClassBasename;
        $suffix = str_replace('\\', '.', $suffix);

        return Container::underscore(sprintf(
            '%s.admin.%s',
            $prefix,
            $suffix
        ));
    }

    /**
     * @return string[]
     */
    private function getAvailableManagerTypes()
    {
        $container = $this->getContainer();

        if (!$container instanceof Container) {
            return array();
        }

        if ($this->managerTypes === null) {
            $this->managerTypes = array();

            foreach ($container->getServiceIds() as $id) {
                if (strpos($id, 'sonata.admin.manager.') === 0) {
                    $managerType = substr($id, 21);
                    $this->managerTypes[$managerType] = $managerType;
                }
            }
        }

        return $this->managerTypes;
    }

    /**
     * @return KernelInterface
     */
    private function getKernel()
    {
        $application = $this->getApplication();
        /* @var $application Application */

        return $application->getKernel();
    }

    /**
     * @return QuestionHelper
     */
    private function getQuestionHelper()
    {
        $questionHelper = $this->getHelper('question');

        if (!$questionHelper instanceof QuestionHelper) {
            $questionHelper = new QuestionHelper();
            $this->getHelperSet()->set($questionHelper);
        }

        return $questionHelper;
    }
}
