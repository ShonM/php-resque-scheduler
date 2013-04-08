<?php

namespace Resque\Scheduler;

use Resque\Resque;
use Resque\Scheduler;
use Resque\Event;

/**
 * Worker to handle scheduling of delayed tasks.
 *
 * @package		ResqueScheduler
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @copyright	(c) 2012 Chris Boulton
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Worker
{
    const LOG_NONE = 0;
    const LOG_NORMAL = 1;
    const LOG_VERBOSE = 2;

    /**
     * @var int Current log level of this worker.
     */
    public $logLevel = 0;

    /**
     * @var int Interval to sleep for between checking schedules.
     */
    protected $interval = 5;

    /**
    * The primary loop for a worker.
    *
    * Every $interval (seconds), the scheduled queue will be checked for jobs
    * that should be pushed to Resque.
    *
    * @param int $interval How often to check schedules.
    */
    public function work($interval = null)
    {
        if ($interval !== null) {
            $this->interval = $interval;
        }

        $this->updateProcLine('Starting');

        while (true) {
            $this->handleDelayedItems();
            $this->sleep();
        }
    }

    /**
     * Handle delayed items for the next scheduled timestamp.
     *
     * Searches for any items that are due to be scheduled in Resque
     * and adds them to the appropriate job queue in Resque.
     *
     * @param DateTime|int $timestamp Search for any items up to this timestamp to schedule.
     */
    public function handleDelayedItems($timestamp = null)
    {
        while (($timestamp = Scheduler::nextDelayedTimestamp($timestamp)) !== false) {
            $this->updateProcLine('Processing Delayed Items');
            $this->enqueueDelayedItemsForTimestamp($timestamp);
        }
    }

    /**
     * Schedule all of the delayed jobs for a given timestamp.
     *
     * Searches for all items for a given timestamp, pulls them off the list of
     * delayed jobs and pushes them across to Resque.
     *
     * @param DateTime|int $timestamp Search for any items up to this timestamp to schedule.
     */
    public function enqueueDelayedItemsForTimestamp($timestamp)
    {
        $item = null;
        while ($item = Scheduler::nextItemForTimestamp($timestamp)) {
            $this->log('queueing ' . $item['class'] . ' in ' . $item['queue'] .' [delayed]');

            if (! empty($item['args'])) {
                $item['args'] = reset($item['args']);
            }

            Event::trigger('beforeDelayedEnqueue', array(
                'queue' => $item['queue'],
                'class' => $item['class'],
                'args'  => $item['args'],
            ));

            Resque::enqueue($item['queue'], $item['class'], $item['args']);
        }
    }

    /**
     * Sleep for the defined interval.
     */
    protected function sleep()
    {
        sleep($this->interval);
    }

    /**
     * Update the status of the current worker process.
     *
     * On supported systems (with the PECL proctitle module installed), update
     * the name of the currently running process to indicate the current state
     * of a worker.
     *
     * @param string $status The updated process title.
     */
    private function updateProcLine($status)
    {
        if (function_exists('setproctitle')) {
            setproctitle('resque-scheduler-' . Scheduler::VERSION . ': ' . $status);
        }
    }

    /**
     * Output a given log message to STDOUT.
     *
     * @param string $message Message to output.
     */
    public function log($message)
    {
        if ($this->logLevel == self::LOG_NORMAL) {
            fwrite(STDOUT, "*** " . $message . "\n");
        } elseif ($this->logLevel == self::LOG_VERBOSE) {
            fwrite(STDOUT, "** [" . strftime('%T %Y-%m-%d') . "] " . $message . "\n");
        }
    }
}
