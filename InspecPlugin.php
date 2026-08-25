<?php

/**
 * @file InspecPlugin.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class InspecPlugin
 *
 * @brief Plugin to export articles to Inspec.
 */

namespace APP\plugins\generic\inspec;

use APP\plugins\PubObjectsExportGenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;

/**
 * Inspec only indexes the first published version of an article, even when later
 * versions carry their own DOIs. An article is therefore deposited once, at its first
 * version of record, and is never re-deposited.
 *
 * The inherited publication hooks exist to make an already-deposited article
 * depositable again whenever a new version is published. That is the opposite of
 * what Inspec wants, so all three are overridden below to do nothing.
 */
class InspecPlugin extends PubObjectsExportGenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        return parent::register($category, $path, $mainContextId);
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.inspec.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.inspec.description');
    }

    /**
     * @copydoc PubObjectsExportGenericPlugin::handlePublicationVersioning()
     *
     * Overridden to do nothing: creating a new version must not clear the deposit
     * status carried over from the version that was already sent to Inspec.
     */
    public function handlePublicationVersioning($hookName, $params): bool
    {
        return Hook::CONTINUE;
    }

    /**
     * @copydoc PubObjectsExportGenericPlugin::handlePublicationPublishing()
     *
     * Overridden to do nothing: publishing a later version must not mark an
     * already-deposited article stale, which would cause it to be re-deposited.
     */
    public function handlePublicationPublishing($hookName, $params): bool
    {
        return Hook::CONTINUE;
    }

    /**
     * @copydoc PubObjectsExportGenericPlugin::handlePublicationUnpublishing()
     *
     * Overridden to do nothing: unpublishing a later version must not mark an
     * earlier, already-deposited version stale.
     */
    public function handlePublicationUnpublishing($hookName, $params): bool
    {
        return Hook::CONTINUE;
    }

    protected function setExportPlugin(): void
    {
        PluginRegistry::register('importexport', new InspecExportPlugin(), $this->getPluginPath());
        $this->exportPlugin = PluginRegistry::getPlugin('importexport', 'InspecExportPlugin');
    }

    /**
     * @copydoc Plugin::getContextSpecificPluginSettingsFile()
     */
    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @copydoc Plugin::getInstallSitePluginSettingsFile()
     */
    public function getInstallSitePluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }
}
