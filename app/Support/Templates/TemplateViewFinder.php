<?php

namespace App\Support\Templates;

use App\Exceptions\TemplatesRefusedException;
use App\Services\TemplateStore;
use Illuminate\View\FileViewFinder;
use InvalidArgumentException;

class TemplateViewFinder extends FileViewFinder
{
    /**
     * Get the fully-qualified location of the view, downloading the tool's
     * template release on demand when a bundled k8s.<folder>.* view is absent.
     *
     * @param  string  $name
     * @return string
     */
    public function find($name)
    {
        try {
            return parent::find($name);
        } catch (InvalidArgumentException $e) {
            if (preg_match('/^k8s\.([a-z0-9-]+)\./', trim((string) $name), $matches) === 1) {
                $store = app(TemplateStore::class);
                $store->ensureFor($matches[1]);

                foreach ($store->refused() as $lockKey) {
                    if (str_starts_with($lockKey, $matches[1].'/')) {
                        throw new TemplatesRefusedException($matches[1]);
                    }
                }

                return parent::find($name);
            }

            throw $e;
        }
    }
}
