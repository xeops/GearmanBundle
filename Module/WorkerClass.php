<?php

/**
 * Gearman Bundle for Symfony2
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Feel free to edit as you please, and have fun.
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 */

namespace Mmoreram\GearmanBundle\Module;

use Doctrine\Common\Annotations\Reader;
use  Symfony\Component\DependencyInjection\ContainerInterface;
use ReflectionClass;

use Mmoreram\GearmanBundle\Driver\Gearman\Job as JobAnnotation;
use Mmoreram\GearmanBundle\Driver\Gearman\Work as WorkAnnotation;
use Mmoreram\GearmanBundle\Module\JobClass as Job;

/**
 * Worker class
 *
 * This class provide all worker definition.
 *
 * @since 2.3.1
 */
class WorkerClass
{
    /**
     * @var string
     *
     * Default description when is not defined
     */
    const DEFAULT_DESCRIPTION = 'No description is defined';

    /**
     * @var string
     *
     * Namespace of worker class
     */
    private $namespace;

    /**
     * @var string
     *
     * Class name of worker
     */
    private $className;

    /**
     * @var string
     *
     * Filename of worker
     */
    private $fileName;

    /**
     * @var string
     *
     * Callable name for this job.
     * If is setted on annotations, this value will be used.
     * Otherwise, natural method name will be used.
     */
    private $callableName;

    /**
     * @var string
     *
     * Service alias if this worker is wanted to be built by dependency injection
     */
    private $service;

    /**
     * @var string
     *
     * Description of Job
     */
    private $description;

    /**
     * @var integer
     *
     * Number of iterations this job will be alive before die
     */
    private $iterations;

    /**
     * @var string
     *
     * Default method this job will be call into Gearman client
     */
    private $defaultMethod;

    /**
     * @var int
     *
     * Job minimum execution time
     */
    private $minimumExecutionTime;

    /**
     * @var int
     *
     * Timeout for idle job
     */
    private $timeout;
    /**
     * @var int|null
     */
    private $memoryLimit;


    /**
     * @var array
     *
     * Collection of servers to connect
     */
    private $servers;

    /**
     * @var JobCollection
     *
     * All jobs inside Worker
     */
    private $jobCollection;

    /**
     * The prefix for all job names
     *
     * @var string $jobPrefix
     */
    private $jobPrefix = null;
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * Retrieves all jobs available from worker
     *
     * @param WorkAnnotation $workAnnotation workAnnotation class
     * @param ReflectionClass $reflectionClass Reflexion class
     * @param Reader $reader Reader class
     * @param array $servers Array of servers defined for Worker
     * @param array $defaultSettings Default settings for Worker
     */
    public function __construct(WorkAnnotation $workAnnotation, ReflectionClass $reflectionClass, Reader $reader, array $servers, array $defaultSettings, ContainerInterface $container)
    {

        $this->namespace = $reflectionClass->getNamespaceName();
        $this->container = $container;

        /**
         * If WorkAnnotation name field is defined, workers_name_prepend_namespace value
         * in defaultSettings array must be checked.
         *
         * If true, namespace must be prepended to workAnnotation name for callableName
         * Otherwise, only workAnnotation value is set as callableName
         */
        $callableNameNamespace = $defaultSettings['workers_name_prepend_namespace']
            ? $this->namespace
            : '';

        /**
         * Setting worker callable name
         */
        $this->callableName = is_null($workAnnotation->name)
            ? $reflectionClass->getName()
            : $callableNameNamespace . $workAnnotation->name;

        $this->callableName = str_replace('\\', '', $this->callableName);

        /**
         * Setting worker description
         */
        $this->description = is_null($workAnnotation->description)
            ? self::DEFAULT_DESCRIPTION
            : $workAnnotation->description;

        $this->fileName = $reflectionClass->getFileName();
        $this->className = $reflectionClass->getName();
        $this->service = $workAnnotation->service;

        if (isset($defaultSettings['job_prefix']))
        {

            $this->jobPrefix = $defaultSettings['job_prefix'];
        }
        $this->servers = $this->loadServers($workAnnotation, $servers);
        $this->iterations = $this->loadIterations($workAnnotation, $defaultSettings);
        $this->defaultMethod = $this->loadDefaultMethod($workAnnotation, $defaultSettings);
        $this->minimumExecutionTime = $this->loadMinimumExecutionTime($workAnnotation, $defaultSettings);
        $this->timeout = $this->loadTimeout($workAnnotation, $defaultSettings);
        $this->memoryLimit = $this->loadMemoryLimit($workAnnotation, $defaultSettings);
        $this->jobCollection = $this->createJobCollection($reflectionClass, $reader);
    }

    /**
     * Load servers
     *
     * If any server is defined in JobAnnotation, this one is used.
     * Otherwise is used servers set in Class
     *
     * @param WorkAnnotation $workAnnotation WorkAnnotation class
     * @param array $servers Array of servers defined for Worker
     *
     * @return array Servers
     */
    private function loadServers(WorkAnnotation $workAnnotation, array $servers)
    {
        /**
         * If is configured some servers definition in the worker, overwrites
         */
        if ($workAnnotation->servers)
        {

            if (is_array($workAnnotation->servers) && !isset($workAnnotation->servers['host']))
            {
                $servers = $workAnnotation->servers;
            }
            elseif (is_string($workAnnotation->servers) && $this->container->hasParameter($workAnnotation->servers))
            {
                $servers = $this->container->getParameter($workAnnotation->servers);
            }
            else
            {
                $servers = array($workAnnotation->servers);
            }
        }

        return $servers;
    }

    /**
     * Load iterations
     *
     * If iterations is defined in WorkAnnotation, this one is used.
     * Otherwise is used set in Class
     *
     * @param WorkAnnotation $workAnnotation WorkAnnotation class
     * @param array $defaultSettings Default settings for Worker
     *
     * @return integer Iteration
     */
    private function loadIterations(WorkAnnotation $workAnnotation, array $defaultSettings)
    {
        return is_null($workAnnotation->iterations)
            ? (int)$defaultSettings['iterations']
            : (int)$workAnnotation->iterations;
    }

    /**
     * Load defaultMethod
     *
     * If defaultMethod is defined in WorkAnnotation, this one is used.
     * Otherwise is used set in Class
     *
     * @param WorkAnnotation $workAnnotation WorkAnnotation class
     * @param array $defaultSettings Default settings for Worker
     *
     * @return string Default method
     */
    private function loadDefaultMethod(WorkAnnotation $workAnnotation, array $defaultSettings)
    {
        return is_null($workAnnotation->defaultMethod)
            ? $defaultSettings['method']
            : $workAnnotation->defaultMethod;
    }

    /**
     * Load minimumExecutionTime
     *
     * If minimumExecutionTime is defined in JobAnnotation, this one is used.
     * Otherwise is used set in Class
     *
     * @param WorkAnnotation $workAnnotation
     * @param array $defaultSettings
     *
     * @return int
     */
    private function loadMinimumExecutionTime(WorkAnnotation $workAnnotation, array $defaultSettings)
    {
        return is_null($workAnnotation->minimumExecutionTime)
            ? (int)$defaultSettings['minimum_execution_time']
            : (int)$workAnnotation->minimumExecutionTime;
    }

    /**
     * Load timeout
     *
     * If timeout is defined in JobAnnotation, this one is used.
     * Otherwise is used set in Class
     *
     * @param WorkAnnotation $workAnnotation
     * @param array $defaultSettings
     *
     * @return int
     */
    private function loadTimeout(WorkAnnotation $workAnnotation, array $defaultSettings)
    {
        return is_null($workAnnotation->timeout)
            ? (int)$defaultSettings['timeout']
            : (int)$workAnnotation->timeout;
    }

    /**
     * Load memory limit for an instance
     * @param WorkAnnotation $workAnnotation
     * @param array $defaultSettings
     * @return int|null
     */
    private function loadMemoryLimit(WorkAnnotation $workAnnotation, array $defaultSettings)
    {
        return is_null($workAnnotation->memoryLimit)
            ? (int)$defaultSettings['memory_limit']
            : (int)$workAnnotation->memoryLimit;
    }

    /**
     * Creates job collection of worker
     *
     * @param ReflectionClass $reflectionClass Reflexion class
     * @param Reader $reader ReaderAnnotation class
     *
     * @return WorkerClass self Object
     */
    private function createJobCollection(ReflectionClass $reflectionClass, Reader $reader)
    {
        $jobCollection = new JobCollection;

        /**
         * For each defined method, we parse it
         */
        foreach ($reflectionClass->getMethods() as $reflectionMethod)
        {

            $methodAnnotations = $reader->getMethodAnnotations($reflectionMethod);

            /**
             * Every annotation found is parsed
             */
            foreach ($methodAnnotations as $methodAnnotation)
            {

                /**
                 * Annotation is only loaded if is typeof JobAnnotation
                 */
                if ($methodAnnotation instanceof JobAnnotation)
                {
                    /**
                     * Creates new Job
                     */
                    $job = new Job($methodAnnotation, $reflectionMethod, $this->callableName, $this->servers, array(
                        'jobPrefix' => $this->jobPrefix,
                        'iterations' => $this->iterations,
                        'method' => $this->defaultMethod,
                        'minimumExecutionTime' => $this->minimumExecutionTime,
                        'timeout' => $this->timeout,
                        'memoryLimit' => $this->memoryLimit,
                    ));

                    $jobCollection->add($job);
                }
            }
        }

        return $jobCollection;
    }

    /**
     * Retrieve all Worker data in cache format
     *
     * @return array
     */
    public function toArray()
    {
        return array(

            'namespace' => $this->namespace,
            'className' => $this->className,
            'fileName' => $this->fileName,
            'callableName' => $this->callableName,
            'description' => $this->description,
            'service' => $this->service,
            'servers' => $this->servers,
            'iterations' => $this->iterations,
            'minimumExecutionTime' => $this->minimumExecutionTime,
            'timeout' => $this->timeout,
            'jobs' => $this->jobCollection->toArray(),
            'memoryLimit' => $this->memoryLimit,
        );
    }

}
