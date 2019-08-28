<?php
/*
This class is used by wod/webp-on-demand.php, which does not do a Wordpress bootstrap, but does register an autoloader for
the WebPExpress classes.

Calling Wordpress functions will FAIL. Make sure not to do that in either this class or the helpers.
*/
//error_reporting(E_ALL);
//ini_set('display_errors', 1);

namespace WebPExpress;

use \WebPExpress\ConvertHelperIndependent;
use \WebPExpress\Sanitize;
use \WebPExpress\SanityCheck;
use \WebPExpress\SanityException;
use \WebPExpress\ValidateException;
use \WebPExpress\Validate;
use \WebPExpress\WodConfigLoader;

class WebPOnDemand extends WodConfigLoader
{

    private static function getSource() {

        //echo 't:' . $_GET['test'];exit;
        // Check if it is in an environment variable
        $source = self::getEnvPassedInRewriteRule('REQFN');
        if ($source !== false) {
            self::$checking = 'source (passed through env)';
            return SanityCheck::absPathExistsAndIsFile($source);
        }

        // Check if it is in header (but only if .htaccess was configured to send in header)
        if (isset($wodOptions['base-htaccess-on-these-capability-tests'])) {
            $capTests = $wodOptions['base-htaccess-on-these-capability-tests'];
            $passThroughHeaderDefinitelyUnavailable = ($capTests['passThroughHeaderWorking'] === false);
            $passThrougEnvVarDefinitelyAvailable =($capTests['passThroughEnvWorking'] === true);
            // This determines if .htaccess was configured to send in querystring
            $headerMagicAddedInHtaccess = ((!$passThrougEnvVarDefinitelyAvailable) && (!$passThroughHeaderDefinitelyUnavailable));
        } else {
            $headerMagicAddedInHtaccess = true;  // pretend its true
        }
        if ($headerMagicAddedInHtaccess && (isset($_SERVER['HTTP_REQFN']))) {
            self::$checking = 'source (passed through request header)';
            return SanityCheck::absPathExistsAndIsFile($_SERVER['HTTP_REQFN']);
        }

        if (!isset(self::$docRoot)) {
            //$source = self::getEnvPassedInRewriteRule('REQFN');
            if (isset($_GET['root-id']) && isset($_GET['xsource-rel-to-root-id'])) {
                $xsrcRelToRootId = SanityCheck::noControlChars($_GET['xsource-rel-to-root-id']);
                $srcRelToRootId = SanityCheck::pathWithoutDirectoryTraversal(substr($xsrcRelToRootId, 1));
                //echo $srcRelToRootId; exit;

                $rootId = SanityCheck::noControlChars($_GET['root-id']);
                SanityCheck::pregMatch('#^[a-z]+$#', $rootId, 'Not a valid root-id');

                $source = self::getRootPathById($rootId) . '/' . $srcRelToRootId;
                return SanityCheck::absPathExistsAndIsFile($source);
            }
        }

        // Check querystring (relative path to docRoot) - when docRoot is available
        if (isset(self::$docRoot) && isset($_GET['xsource-rel'])) {
            self::$checking = 'source (passed as relative path, through querystring)';
            $xsrcRel = SanityCheck::noControlChars($_GET['xsource-rel']);
            $srcRel = SanityCheck::pathWithoutDirectoryTraversal(substr($xsrcRel, 1));
            return SanityCheck::absPathExistsAndIsFile(self::$docRoot . '/' . $srcRel);
        }

        // Check querystring (relative path to plugin) - when docRoot is unavailable
        /*TODO
        if (!isset(self::$docRoot) && isset($_GET['xsource-rel-to-plugin-dir'])) {
            self::$checking = 'source (passed as relative path to plugin dir, through querystring)';
            $xsrcRelPlugin = SanityCheck::noControlChars($_GET['xsource-rel-to-plugin-dir']);
            $srcRelPlugin = SanityCheck::pathWithoutDirectoryTraversal(substr($xsrcRelPlugin, 1));
            return SanityCheck::absPathExistsAndIsFile(self::$docRoot . '/' . $srcRel);
        }*/


        // Check querystring (full path)
        // - But only on Nginx (our Apache .htaccess rules never passes absolute url)
        if (
            (stripos($_SERVER["SERVER_SOFTWARE"], 'nginx') !== false) &&
            (isset($_GET['source']) || isset($_GET['xsource']))
        ) {
            self::$checking = 'source (passed as absolute path on nginx)';
            if (isset($_GET['source'])) {
                $source = SanityCheck::absPathExistsAndIsFile($_GET['source']);
            } else {
                $xsrc = SanityCheck::noControlChars($_GET['xsource']);
                return SanityCheck::absPathExistsAndIsFile(substr($xsrc, 1));
            }
        }

        // Last resort is to use $_SERVER['REQUEST_URI'], well knowing that it does not give the
        // correct result in all setups (ie "folder method 1")
        if (isset(self::$docRoot)) {
            self::$checking = 'source (retrieved by the request_uri server var)';
            $srcRel = SanityCheck::pathWithoutDirectoryTraversal(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
            return SanityCheck::absPathExistsAndIsFile(self::$docRoot . $srcRel);
        }
    }

    private static function processRequestNoTryCatch() {

        self::loadConfig();

        $options = self::$options;
        $wodOptions = self::$wodOptions;
        $serveOptions = $options['webp-convert'];
        $convertOptions = &$serveOptions['convert'];
        //echo '<pre>' . print_r($wodOptions, true) . '</pre>'; exit;


        // Validate that WebPExpress was configured to redirect to this conversion script
        // (but do not require that for Nginx)
        // ------------------------------------------------------------------------------
        self::$checking = 'settings';
        if (stripos($_SERVER["SERVER_SOFTWARE"], 'nginx') === false) {
            if (!isset($wodOptions['enable-redirection-to-converter']) || ($wodOptions['enable-redirection-to-converter'] === false)) {
                throw new ValidateException('Redirection to conversion script is not enabled');
            }
        }

        // Check source (the image to be converted)
        // --------------------------------------------
        self::$checking = 'source';
        $source = self::getSource();

        //echo $source; exit;

        $imageRoots = self::getImageRoots();

        // Get upload dir
        $uploadDirAbs = $imageRoots->byId('uploads')->getAbsPath();

        // Check destination path
        // --------------------------------------------
        self::$checking = 'destination path';
        $destination = ConvertHelperIndependent::getDestination(
            $source,
            $wodOptions['destination-folder'],
            $wodOptions['destination-extension'],
            self::$webExpressContentDirAbs,
            $uploadDirAbs,
            $usingDocRoot,
            self::getImageRoots()
        );
        //echo 'dest:' . $destination; exit;

        //$destination = SanityCheck::absPathIsInDocRoot($destination);
        $destination = SanityCheck::pregMatch('#\.webp$#', $destination, 'Does not end with .webp');


        // Done with sanitizing, lets get to work!
        // ---------------------------------------
        if (isset($wodOptions['success-response']) && ($wodOptions['success-response'] == 'original')) {
            $serveOptions['serve-original'] = true;
            $serveOptions['serve-image']['headers']['vary-accept'] = false;
        } else {
            $serveOptions['serve-image']['headers']['vary-accept'] = true;
        }
//echo $source . '<br>' . $destination; exit;

        ConvertHelperIndependent::serveConverted(
            $source,
            $destination,
            $serveOptions,
            self::$webExpressContentDirAbs . '/log',
            'Conversion triggered with the conversion script (wod/webp-on-demand.php)'
        );
    }

    public static function processRequest() {
        try {
            self::processRequestNoTryCatch();
        } catch (SanityException $e) {
            self::exitWithError('Sanity check failed for ' . self::$checking . ': '. $e->getMessage());
        } catch (ValidateException $e) {
            self::exitWithError('Validation failed for ' . self::$checking . ': '. $e->getMessage());
        } catch (\Exception $e) {
            self::exitWithError('Error occured while calculating ' . self::$checking . ': '. $e->getMessage());
        }
    }
}
