<?php

/**
 * @file InspecInfoSender.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class InspecInfoSender
 *
 * @brief Scheduled task to send deposits to Inspec.
 */

namespace APP\plugins\generic\inspec;

use APP\core\Application;
use APP\journal\Journal;
use APP\publication\Publication;
use APP\submission\Submission;
use Exception;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;

class InspecInfoSender extends ScheduledTask
{
    public ?InspecExportPlugin $plugin = null;

    /**
     * Constructor.
     */
    public function __construct(array $args = [])
    {
        PluginRegistry::loadCategory('importexport');

        /** @var InspecExportPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('importexport', 'InspecExportPlugin');
        $this->plugin = $plugin;

        if ($plugin instanceof InspecExportPlugin) {
            $plugin->addLocaleData();
        }

        parent::__construct($args);
    }

    /**
     * @copydoc ScheduledTask::getName()
     */
    public function getName(): string
    {
        return __('plugins.importexport.inspec.senderTask.name');
    }

    /**
     * @copydoc ScheduledTask::executeActions()
     *
     * @throws Exception
     */
    public function executeActions(): bool
    {
        if (!$this->plugin) {
            return false;
        }

        $plugin = $this->plugin;
        $journals = $this->getJournals();

        // Always deposit at the submission level, even when the journal versions
        // its DOIs. Inspec only indexes the first published version of an article,
        // so individual versions are never deposited as separate objects.
        foreach ($journals as $journal) {
            $depositableArticles = $plugin->getAllDepositableArticles($journal);
            if (count($depositableArticles)) {
                $this->registerObjects($depositableArticles, $journal);
            }
        }

        return true;
    }

    /**
     * Get all journals that meet the requirements to have
     * their articles automatically sent to Inspec.
     *
     * @throws Exception
     *
     * @return array<Journal>
     */
    protected function getJournals(): array
    {
        $plugin = $this->plugin;
        $contextDao = Application::getContextDAO();
        $journalFactory = $contextDao->getAll(true);

        $journals = [];
        while ($journal = $journalFactory->next()) { /** @var Journal $journal */
            $journalId = $journal->getId();
            $connectionSettings = $plugin->getConnectionSettings($journal);
            if (
                empty($connectionSettings['host']) ||
                empty($connectionSettings['username']) ||
                empty($connectionSettings['password']) ||
                !$plugin->getSetting($journalId, 'enabled') ||
                !$plugin->getSetting($journalId, 'automaticRegistration')
            ) {
                continue;
            }
            $journals[] = $journal;
        }
        return $journals;
    }


    /**
     * Register articles or publications
     *
     * @param array<Submission|Publication> $objects
     *
     * @throws Exception
     */
    protected function registerObjects(array $objects, Journal $journal): void
    {
        $plugin = $this->plugin;
        foreach ($objects as $object) {
            $result = $plugin->depositXML([$object], $journal);
            if ($result !== true) {
                $this->addLogEntry($result);
            }
        }
    }

    /**
     * Add execution log entry
     *
     * @throws Exception
     */
    protected function addLogEntry(array $error): void
    {
        if (count($error) === 0) {
            throw new Exception('Invalid error message');
        }
        $this->addExecutionLogEntry(
            __($error[0], ['param' => $error[1] ?? null]),
            ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING
        );
    }
}
