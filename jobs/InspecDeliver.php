<?php

/**
 * @file jobs/InspecDeliver.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class InspecDeliver
 *
 * @ingroup jobs
 *
 * @brief Build an article's package and deposit it to the configured Inspec SFTP account.
 */

namespace APP\plugins\generic\inspec\jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\inspec\InspecExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\job\exceptions\JobException;
use PKP\jobs\BaseJob;
use PKP\plugins\PluginRegistry;
use Throwable;

class InspecDeliver extends BaseJob
{
    /**
     * Building a package reads the JATS and the PDF galley from disk and may validate
     * against the DTD, so allow more than the default minute for it and the upload.
     */
    public int $timeout = 180;

    public function __construct(
        protected int $objectId,
        protected bool $isPublication,
        protected int $contextId,
        protected ?bool $noValidation = null
    ) {
        parent::__construct();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /** @var Submission|Publication|null $object */
        $object = $this->isPublication
            ? Repo::publication()->get($this->objectId)
            : Repo::submission()->get($this->objectId);

        if (!$object) {
            throw new JobException(JobException::INVALID_PAYLOAD);
        }

        PluginRegistry::register('importexport', new InspecExportPlugin(), 'plugins/generic/inspec/InspecExportPlugin', $this->contextId);
        /** @var InspecExportPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('importexport', 'InspecExportPlugin');

        // Queuing a delivery marks the article submitted, so a status still reading
        // "registered" here means an earlier attempt of this job already delivered it.
        if ($object->getData($plugin->getDepositStatusSettingName()) === PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED) {
            return;
        }

        $context = Application::getContextDAO()->getById($this->contextId);
        $package = $plugin->createZip($object, $context, $this->noValidation);
        if (isset($package['error'])) {
            $errorMessage = $plugin->convertErrorMessage($package['error']);
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            throw new JobException($errorMessage);
        }

        try {
            $plugin->deliverToEndpoint($package['path'], $package['filename'] . '.zip', $context);
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED);
        } catch (Throwable $e) {
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $e->getMessage());
            throw new JobException($e->getMessage());
        } finally {
            $plugin->deleteTempFile($package['path']);
        }
    }
}
