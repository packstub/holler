<?php

namespace Packstub\Calls\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Serves the browser engine, the widget and its stylesheet straight from the
 * package, so an app needs no publish step and no bundler. `Calls::asset()`
 * builds the URL with a version in it, and the files are cached for a year.
 */
class AssetController
{
    public const FILES = [
        'index.js' => 'text/javascript',
        'widget.js' => 'text/javascript',
        'widget.css' => 'text/css',
    ];

    public function __invoke(Request $request, string $file): BinaryFileResponse
    {
        abort_unless(isset(self::FILES[$file]), 404);

        // A versioned URL (`Calls::asset()`) is cached for a year; the widget's own import of
        // `./index.js` has no version, so that one is revalidated against its ETag.
        $response = new BinaryFileResponse(static::path($file), 200, [
            'Content-Type' => self::FILES[$file].'; charset=utf-8',
            'Cache-Control' => $request->query('v') ? 'public, max-age=31536000, immutable' : 'public, no-cache',
        ]);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_INLINE, $file);
        $response->setAutoEtag()->setAutoLastModified()->isNotModified($request);

        return $response;
    }

    public static function path(string $file): string
    {
        return dirname(__DIR__, 2).'/resources/dist/'.$file;
    }

    /** A short hash of the file's contents, for the URL. */
    public static function version(string $file): string
    {
        return substr(md5_file(static::path($file)) ?: '0', 0, 8);
    }
}
