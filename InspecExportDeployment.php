<?php

/**
 * @file InspecExportDeployment.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class InspecExportDeployment
 *
 * @brief Base class configuring the Inspec export process to an
 * application's specifics.
 */

namespace APP\plugins\generic\inspec;

use APP\plugins\PubObjectCache;
use PKP\context\Context;
use PKP\plugins\Plugin;

class InspecExportDeployment
{
    public Context $context;
    public Plugin $plugin;

    /**
     * Get the plugin cache
     */
    public function getCache(): PubObjectCache
    {
        return $this->plugin->getCache();
    }

    /**
     * Constructor
     *
     */
    public function __construct(Context $context, InspecExportPlugin $plugin)
    {
        $this->setContext($context);
        $this->setPlugin($plugin);
    }

    //
    // Deployment items for subclasses to override
    //
    /**
     * Get the root element name
     */
    public function getRootElementName(): string
    {
        return 'metadata';
    }

    //
    // Getter/setters
    //
    /**
     * Set the import/export context.
     */
    public function setContext(Context $context): void
    {
        $this->context = $context;
    }

    /**
     * Get the import/export context.
     */
    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * Set the import/export plugin.
     */
    public function setPlugin(Plugin $plugin): void
    {
        $this->plugin = $plugin;
    }

    /**
     * Get the import/export plugin.
     */
    public function getPlugin(): Plugin
    {
        return $this->plugin;
    }
}
