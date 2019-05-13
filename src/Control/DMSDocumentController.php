<?php

namespace Sunnysideup\DMS\Control;

use InvalidArgumentException;

use SilverStripe\Versioned\Versioned;
use SilverStripe\Core\Convert;
use SilverStripe\Control\Director;
use Sunnysideup\DMS\Model\DMSDocument_versions;
use SilverStripe\ORM\DataObject;
use Sunnysideup\DMS\Model\DMSDocument;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Controller;

class DMSDocumentController extends Controller
{
    /**
     * Mode to switch for testing. Does not return document download, just document URL.
     *
     * @var boolean
     */
    public static $testMode = false;

    private static $allowed_actions = array(
        'index'
    );

    public function init()
    {
        Versioned::choose_site_stage($this->request);
        parent::init();
    }

    /**
     * Returns the document object from the request object's ID parameter.
     * Returns null, if no document found
     *
     * @param  SS_HTTPRequest $request
     * @return DMSDocument|null
     */
    protected function getDocumentFromID($request)
    {
        $doc = null;

        $id = Convert::raw2sql($request->param('ID'));
        if (strpos($id, 'version') === 0) {
            // Versioned document
            $id = $this->getDocumentIdFromSlug(str_replace('version', '', $id));
            $doc = DataObject::get_by_id(DMSDocument_versions::class, $id);
            $this->extend('updateVersionFromID', $doc, $request);
        } else {
            $slugID = $this->getDocumentIdFromSlug($id);
            // Normal document
            $doc = DataObject::get_by_id(DMSDocument::class, $slugID);
            //backwards compatibility - fall back to OriginalDMSDocumentID
            if(!$doc){
                $doc = DMSDocument::get()->filter(['OriginalDMSDocumentID' => $slugID])->first();
            }
            $this->extend('updateDocumentFromID', $doc, $request);
        }

        return $doc;
    }

    /**
     * Get a document's ID from a "friendly" URL slug containing a numeric ID and slugged title
     *
     * @param  string $slug
     * @return int
     * @throws InvalidArgumentException if an invalid format is provided
     */
    protected function getDocumentIdFromSlug($slug)
    {
        $parts = (array) sscanf($slug, '%d-%s');
        $id = array_shift($parts);
        if (is_numeric($id)) {
            return (int) $id;
        }
        throw new InvalidArgumentException($slug . ' is not a valid DMSDocument URL');
    }

    /**
     * Access the file download without redirecting user, so we can block direct
     * access to documents.
     */
    public function index(HTTPRequest $request)
    {
        $doc = $this->getDocumentFromID($request);

        if (!empty($doc)) {
            $canView = $doc->canView();

            if ($canView) {

                $baseDir = Director::baseFolder();
                $baseDirWithPublic = $baseDir . '/public/';
                $path = $baseDirWithPublic . $doc->getURL();
                if ($doc->exists()) {
                    $fileBin = trim(`whereis file`);
                    if (function_exists('finfo_file')) {
                        // discover the mime type properly
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $path);
                    } elseif (is_executable($fileBin)) {
                        $path = escapeshellarg($path);
                        // try to use the system tool
                        $mime = `$fileBin -i -b $path`;
                        $mime = explode(';', $mime);
                        $mime = trim($mime[0]);
                    } else {
                        // make do with what we have
                        $ext = $doc->getExtension();
                        if ($ext == 'pdf') {
                            $mime = 'application/pdf';
                        } elseif ($ext == 'html' || $ext =='htm') {
                            $mime = 'text/html';
                        } else {
                            $mime = 'application/octet-stream';
                        }
                    }

                    if (self::$testMode) {
                        return $path;
                    }

                    // set fallback if no config nor file-specific value
                    $disposition = 'attachment';

                    // file-specific setting


                    //if a DMSDocument can be downloaded and all the permissions/privileges has passed,

                    return $this->sendFile($path, $mime, $doc->Name, $disposition);
                }
            }
        }

        if (self::$testMode) {
            return 'This asset does not exist.';
        }
        $this->httpError(404, 'This asset does not exist.');
    }

    /**
     * @param string $path File path
     * @param string $mime File mime type
     * @param string $name File name
     * @param string $disposition Content dispositon
     */
    protected function sendFile($path, $mime, $name, $disposition)
    {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path), null);
        if (!empty($mime) && $mime != "text/html") {
            header('Content-Disposition: '.$disposition.'; filename="'.addslashes($name).'"');
        }
        header('Content-transfer-encoding: 8bit');
        header('Expires: 0');
        header('Pragma: cache');
        header('Cache-Control: private');
        flush();
        readfile($path);
        exit;
    }
}