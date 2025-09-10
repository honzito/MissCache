<?php

namespace MissCache\Util;

interface PluginInterface
{
    /**
     * Whether this plugin handles the given route prefix (first segment).
     * Example: "phpThumbCache".
     */
    public function getRoutePrefix(): string;

    /**
     * Generate the cached file if missing.
     * Implementations must write the artifact to $req->filesystemPath().
     * Should return true on success (file exists at path), false otherwise.
     */
    public function generate(CacheRequest $req): bool;
}