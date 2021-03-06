<?php

namespace Egulias\ListenersDebugCommandBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;


/**
 * ListenersCommand
 *
 * @author Eduardo Gulias <me@egulias.com>
 */
class ListenersCommand extends ContainerAwareCommand
{

    /**
     * @var ContainerBuilder
     */
    protected $containerBuilder;

    protected $listeners = array();
    /**
     * @see Command
     */
    protected function configure()
    {
        $this
            ->setDefinition(array(
                  new InputArgument('name', InputArgument::OPTIONAL, 'A service name (foo)  or search (foo*)'),
                  new InputOption('show-private', null, InputOption::VALUE_NONE, 'Use to show public *and* private services listeners'),
                )
            )
            ->setName('container:debug:listeners')
            ->setDescription('Displays current services defined as listeners for an application')
            ->setHelp(<<<EOF
The <info>container:debug:listeners</info> command displays all configured <comment>public</comment> services definded as listeners:

  <info>container:debug:listeners</info>

EOF
            )
        ;
    }


    /**
     * @see Command
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');

        $this->containerBuilder = $this->getContainerBuilder();
        //$serviceIds = $this->containerBuilder->getServiceIds();
        $listenersIds = $this->getListenersIds();

        // sort so that it reads like an index of services
        asort($listenersIds);

        if ($name) {
            $this->outputListener($output, $name);
        } else {
            $this->outputListeners($output, $listenersIds, $input->getOption('show-private'));
        }
    }

    /**
     * getListenersIds
     *
     * Searches for any number of defined listeners under the tag "*.event_listener"
     *
     * @return array listeners ids
     */
    protected function getListenersIds()
    {
        $listenersIds = array();
        if (!$this->containerBuilder->hasDefinition('event_dispatcher')) {
            return $listenersIds;
        }

        $definition = $this->containerBuilder->getDefinition('event_dispatcher');
        $dfs = $this->containerBuilder->getDefinitions();

        foreach ($dfs as $k => $v) {
            $tags = $v->getTags();
            if (count($tags) <= 0) {
                continue;
            }
            $keys = array_keys($tags);
            $tag = $keys[0];
            if (preg_match('/.+\.event_listener/', $tag)) {
                $services = $this->containerBuilder->findTaggedServiceIds($tag);
                foreach ($services as $id => $events) {
                    $this->listeners[$id]['tag'] = $tags[$tag][0];
                    $listenersIds[$id] = $id;
                }
            }
        }
        return $listenersIds;
    }


    /**
     * outputListeners
     *
     * @param OutputInterface $output       Output
     * @param array           $listenersIds array of listeners ids
     * @param boolean         $showPrivate  Show private listeners
     *
     * @return void
     */
    protected function outputListeners(OutputInterface $output, $listenersIds, $showPrivate = false)
    {
        // set the label to specify public or public+private
        if ($showPrivate) {
            $label = '<comment>Public</comment> and <comment>private</comment> (services) listeners';
        } else {
            $label = '<comment>Public</comment> (services) listeners';
        }

        $output->writeln($this->getHelper('formatter')->formatSection('container', $label));

        // loop through to get space needed and filter private services
        $maxName = 4;
        $maxScope = 30;
        foreach ($listenersIds as $key => $serviceId) {
            $definition = $this->resolveServiceDefinition($serviceId);

            if ($definition instanceof Definition) {
                // filter out private services unless shown explicitly
                if (!$showPrivate && !$definition->isPublic()) {
                    unset($serviceIds[$key]);
                    continue;
                }

                if (strlen($definition->getScope()) > $maxScope) {
                    $maxScope = strlen($definition->getScope());
                }
            }

            if (strlen($serviceId) > $maxName) {
                $maxName = strlen($serviceId);
            }
        }
        $format  = '%-'.$maxName.'s %-'.$maxScope.'s %s';

        // the title field needs extra space to make up for comment tags
        $format1  = '%-'.($maxName + 19).'s %-'.($maxScope + 50).'s %s';
        $output->writeln(sprintf($format1, '<comment>Name</comment>', '<comment>Event</comment>', '<comment>Class Name</comment>'));

        foreach ($listenersIds as $serviceId) {
            $definition = $this->resolveServiceDefinition($serviceId);

            if ($definition instanceof Definition) {
                $output->writeln(
                    sprintf($format, $serviceId, $this->listeners[$serviceId]['tag']['event'], $definition->getClass())
                );
            } elseif ($definition instanceof Alias) {
                $alias = $definition;
                $output->writeln(
                    sprintf($format, $serviceId, 'n/a', sprintf('<comment>alias for</comment> <info>%s</info>', (string) $alias))
                );
            } else {
                // we have no information (happens with "service_container")
                $service = $definition;
                $output->writeln(sprintf($format, $serviceId, '', get_class($service)));
            }
        }
    }

    /**
     * Renders detailed service information about one listener
     */
    protected function outputListener(OutputInterface $output, $serviceId)
    {
        $definition = $this->resolveServiceDefinition($serviceId);

        $label = sprintf('Information for listener <info>%s</info>', $serviceId);
        $output->writeln($this->getHelper('formatter')->formatSection('container', $label));
        $output->writeln('');

        if ($definition instanceof Definition) {
            $output->writeln(sprintf('<comment>Listener Id</comment>   %s', $serviceId));
            $output->writeln(sprintf('<comment>Class</comment>         %s', $definition->getClass()));
            $output->writeln(sprintf('<comment>Listens to:</comment>', ''));

            $tags = $definition->getTags();
            foreach ($tags as $tag => $details) {
                foreach ($details as $current) {
                    if (preg_match('/.+\.event_listener/', $tag)) {
                        $output->writeln(sprintf('<comment>  -Event</comment>         %s', $current['event']));
                        $output->writeln(sprintf('<comment>  -Method</comment>        %s', $current['method']));
                        $priority = (isset($current['priority'])) ? $current['priority'] : 0;
                        $output->writeln(sprintf('<comment>  -Priority</comment>      %s', $priority));
                    }
                }
            }
            $tags = $definition->getTags() ? implode(', ', array_keys($definition->getTags())) : '-';
            $output->writeln(sprintf('<comment>Tags</comment>         %s', $tags));
            $public = $definition->isPublic() ? 'yes' : 'no';
            $output->writeln(sprintf('<comment>Public</comment>       %s', $public));
        } elseif ($definition instanceof Alias) {
            $alias = $definition;
            $output->writeln(sprintf('This service is an alias for the service <info>%s</info>', (string) $alias));
        } else {
            // edge case (but true for "service_container", all we have is the service itself
            $service = $definition;
            $output->writeln(sprintf('<comment>Service Id</comment>   %s', $serviceId));
            $output->writeln(sprintf('<comment>Class</comment>        %s', get_class($service)));
        }
    }

    /**
     * Loads the ContainerBuilder from the cache.
     *
     * @return ContainerBuilder
     */
    protected function getContainerBuilder()
    {
        if (!$this->getApplication()->getKernel()->isDebug()) {
            throw new \LogicException(sprintf('Debug information about the container is only available in debug mode.'));
        }

        if (!file_exists($cachedFile = $this->getContainer()->getParameter('debug.container.dump'))) {
            throw new \LogicException(sprintf('Debug information about the container could not be found. Please clear the cache and try again.'));
        }

        $container = new ContainerBuilder();

        $loader = new XmlFileLoader($container, new FileLocator());
        $loader->load($cachedFile);

        return $container;
    }


    /**
     * Given an array of service IDs, this returns the array of corresponding
     * Definition and Alias objects that those ids represent.
     *
     * @param string $serviceId The service id to resolve
     *
     * @return \Symfony\Component\DependencyInjection\Definition|\Symfony\Component\DependencyInjection\Alias
     *
     * @see FrameworkBundle\Command\ContainerDebugCommand
     */
    protected function resolveServiceDefinition($serviceId)
    {
        if ($this->containerBuilder->hasDefinition($serviceId)) {
            return $this->containerBuilder->getDefinition($serviceId);
        }

        // Some service IDs don't have a Definition, they're simply an Alias
        if ($this->containerBuilder->hasAlias($serviceId)) {
            return $this->containerBuilder->getAlias($serviceId);
        }

        // the service has been injected in some special way, just return the service
        return $this->containerBuilder->get($serviceId);
    }

}
