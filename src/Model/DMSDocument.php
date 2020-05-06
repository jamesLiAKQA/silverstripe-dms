<?php

namespace Sunnysideup\DMS\Model;

use Exception;

use Sunnysideup\DMS\Model\DMSDocumentSet;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Security\Member;
use Sunnysideup\DMS\Model\DMSDocument;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataExtension;
use SilverStripe\View\Parsers\URLSegmentFilter;
use SilverStripe\Control\Director;
use SilverStripe\Control\Controller;
use SilverStripe\Versioned\Versioned;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use Sunnysideup\DMS\DMS;
use SilverStripe\View\Requirements;
use SilverStripe\Forms\FieldList;
use Sunnysideup\DMS\Tools\ShortCodeRelationFinder;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\LiteralField;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\DateField_Disabled;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldConfig_RelationEditor;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use Sunnysideup\DMS\Cms\DMSGridFieldEditButton;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldAddExistingAutocompleter;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\Tab;
use SilverStripe\Core\Convert;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\ORM\DataObject;
use Sunnysideup\DMS\Interfaces\DMSDocumentInterface;
use Sunnysideup\DMS\Admin\DMSDocumentAdmin;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Injector\Injector;

/**
 * @package dms
 *
 * @property Text Description
 *
 * @method ManyManyList RelatedDocuments
 * @method ManyManyList ViewerGroups
 * @method ManyManyList EditorGroups
 *
 * @method Member CreatedBy
 * @property Int CreatedByID
 * @method Member LastEditedBy
 * @property Int LastEditedByID
 *
 */
class DMSDocument extends File implements DMSDocumentInterface
{


    private static $singular_name = 'Document';

    private static $plural_name = 'Documents';

    private static $table_name = 'DMSDocument';

    private static $db = [
        "Description" => 'Text'
    ];

    private static $has_one = [
        'CoverImage' => Image::class,
        'TempFile' => File::class,
        'CreatedBy' => Member::class,
        'LastEditedBy' => Member::class
    ];

    private static $owns = [
        'CoverImage',
        'TempFile'
    ];

    private static $many_many = [
        'RelatedDocuments' => DMSDocument::class
    ];

    private static $belongs_many_many = [
        'Sets' => DMSDocumentSet::class
    ];

    private static $searchable_fields = [
        'Name' => 'PartialMatchFilter',
        'Title' => 'PartialMatchFilter',
        'CreatedBy.Surname' => 'PartialMatchFilter',
        'LastEditedBy.Surname' => 'PartialMatchFilter',
        'ShowInSearch' => 'ExactMatchFilter',
    ];

    private static $summary_fields = [
        'Name' => 'Filename',
        'Title' => 'Title',
        'CreatedBy.Title' => 'Creator',
        'LastEditedBy.Title' => 'Last Editor',
        'Version' => 'Version',
        'getRelatedPages.count' => 'Page Use',
    ];

    private static $casting = [
        'IdAndTitle' => 'Varchar'
    ];

    private static $do_not_copy = [
        'ID',
        'ClassName',
        'OwnerID'
    ];

    private static $only_copy_if_empty = [
        'LastEdited',
        'Created',
        'Version',
        'CanViewType',
        'CanEditType',
        'ShowInSearch',
        'FileHash',
        'FileFilename',
        'FileVariant'
    ];

    /**
     * Return the type of file for the given extension
     * on the current file name.
     *
     * @param string $ext
     *
     * @return string
     */
    public static function get_file_type($ext)
    {
        $types = array(
            'gif' => 'GIF image - good for diagrams',
            'jpg' => 'JPEG image - good for photos',
            'jpeg' => 'JPEG image - good for photos',
            'png' => 'PNG image - good general-purpose format',
            'ico' => 'Icon image',
            'tiff' => 'Tagged image format',
            'doc' => 'Word document',
            'xls' => 'Excel spreadsheet',
            'zip' => 'ZIP compressed file',
            'gz' => 'GZIP compressed file',
            'dmg' => 'Apple disk image',
            'pdf' => 'Adobe Acrobat PDF file',
            'mp3' => 'MP3 audio file',
            'wav' => 'WAV audo file',
            'avi' => 'AVI video file',
            'mpg' => 'MPEG video file',
            'mpeg' => 'MPEG video file',
            'js' => 'Javascript file',
            'css' => 'CSS file',
            'html' => 'HTML file',
            'htm' => 'HTML file'
        );

        return isset($types[$ext]) ? $types[$ext] : $ext;
    }

    /**
     * @return FieldList
     */
    public function getCMSFields()
    {

        $siteConfig = SiteConfig::current_site_config();
        $fieldsForMain = [];
        $fieldsForDetails = [];
        $fieldsForTags = [];
        $fieldsForVersions = [];
        $fieldsForRelatedDocs = [];
        $fieldsForRelated = [];
        $fieldsForPermissions = [];
        if (!(Controller::curr() instanceof DMSDocumentAdmin)) {
            if ($this->exists()) {
                $fieldsForMain[] = LiteralField::create(
                    'LinkToEdit',
                    '<h2 style="text-align: center; padding-bottom: 30px;">«
                        You can edit this DMS Document in the
                        <a href="' . $this->CMSEditLink() . '" target="dms">DMS Document Editor</a>
                    »</h2>'
                );
            } else {
                $fieldsForMain[] = LiteralField::create(
                    'LinkToEdit',
                    '<h2 style="text-align: center; padding-bottom: 30px;">«
                        You can add a new DMS Document in the
                        <a href="' . $this->CMSAddLink() . '" target="dms">DMS Document Editor</a>
                    »</h2>'
                );
            }
        }
        if (!$siteConfig->DMSFolderID) {
            $fieldsForMain[] = (LiteralField::create(
                    'DMSFolderMessage',
                    '<h2>You need to <a href="/admin/settings/" target="_blank">set</a> the folder for the DMS documents before you can create a DMS document.'
                ));
        } else {
            if (!$this->ID) {
                $uploadField = new UploadField('TempFile', 'File');
                $uploadField->setAllowedMaxFileNumber(1);
                $fieldsForMain[] = $uploadField;
            } else {
                $infoFields = $this->getFieldsForFile();
                $fieldsForMain[] = $infoFields;

                $uploadField = new UploadField('TempFile', 'Replace Current File');
                $uploadField->setAllowedMaxFileNumber(1);
                $fieldsForDetails[] = $uploadField;

                $fieldsForDetails[] = TextField::create('Title', _t('DMSDocument.TITLE', 'Title'));
                $fieldsForDetails[] = TextareaField::create('Description', _t('DMSDocument.DESCRIPTION', 'Description'));

                if ($this->hasExtension('Sunnysideup\DMS\Extensions\DMSDocumentTaxonomyExtension')) {
                    $tags = $this->getAllTagsMap();
                    $tagField = ListboxField::create('Tags', _t('DMSDocumentTaxonomyExtension.TAGS', 'Tags'))
                        ->setSource($tags);

                    if (empty($tags)) {
                        $tagField->setAttribute('data-placeholder', _t('DMSDocumentTaxonomyExtension.NOTAGS', 'No tags found'));
                    }

                    $fieldsForTags[] = $tagField;
                }

                $coverImageField = UploadField::create('CoverImage', _t('DMSDocument.COVERIMAGE', 'Cover Image'));
                $coverImageField->getValidator()->setAllowedExtensions(array('jpg', 'jpeg', 'png', 'gif'));
                $coverImageField->setAllowedMaxFileNumber(1);
                $fieldsForDetails[] = $coverImageField;

                $gridFieldConfig = GridFieldConfig::create()->addComponents(
                    new GridFieldToolbarHeader(),
                    new GridFieldSortableHeader(),
                    new GridFieldDataColumns(),
                    new GridFieldPaginator(30),
                    //new GridFieldEditButton(),
                    new GridFieldDetailForm()
                );

                $gridFieldConfig->getComponentByType(GridFieldDataColumns::class)
                    ->setDisplayFields(array(
                        'Title' => 'Title',
                        'ClassName' => 'Page Type',
                        'ID' => 'Page ID'
                    ))
                    ->setFieldFormatting(array(
                        'Title' => sprintf(
                            '<a class=\"cms-panel-link\" href=\"%s/$ID\">$Title</a>',
                            singleton(CMSPageEditController::class)->Link('show')
                        )
                    ));

                $pagesGrid = GridField::create(
                    'Pages',
                    _t('DMSDocument.RelatedPages', 'Related Pages'),
                    $this->getRelatedPages(),
                    $gridFieldConfig
                );

                $fieldsForRelated[] = $pagesGrid;

                if ($this->canEdit()) {
                    $fieldsForRelatedDocs[] = $this->getRelatedDocumentsGridField();

                    $versionsGridFieldConfig = GridFieldConfig::create()->addComponents(
                        new GridFieldToolbarHeader(),
                        new GridFieldSortableHeader(),
                        new GridFieldDataColumns(),
                        new GridFieldPaginator(30)
                    );

                    $versionsGrid =  GridField::create(
                        'Versions',
                        _t('DMSDocument.Versions', 'Versions'),
                        Versioned::get_all_versions(DMSDocument::class, $this->ID),
                        $versionsGridFieldConfig
                    );

                    $fieldsForVersions[] = $versionsGrid;

                    $fieldsForPermissions[] = $this->getPermissionsActionPanel();
                }
            }
        }

        $fields = new FieldList(
            $rootTab = new TabSet(
                "Root",
                $tabBehaviour = new Tab(
                    'Main'
                )
            )
        );
        foreach ($fieldsForMain as $field) {
            $fields->addFieldToTab('Root.Main', $field);
        }
        foreach ($fieldsForDetails as $field) {
            $fields->addFieldToTab('Root.EditDetails', $field);
        }
        foreach ($fieldsForTags as $field) {
            $fields->addFieldToTab('Root.Tags', $field);
        }
        foreach ($fieldsForVersions as $field) {
            $fields->addFieldToTab('Root.Versions', $field);
        }
        foreach ($fieldsForRelatedDocs as $field) {
            $fields->addFieldToTab('Root.RelatedDocs', $field);
        }
        foreach ($fieldsForRelated as $field) {
            $fields->addFieldToTab('Root.Usage', $field);
        }
        foreach ($fieldsForPermissions as $field) {
            $fields->addFieldToTab('Root.Permissions', $field);
        }
        $this->extend('updateCMSFields', $fields);

        return $fields;
    }


    /**
     * Adds permissions selection fields to a composite field and returns so it can be used in the "actions panel"
     *
     * @return CompositeField
     */
    public function getPermissionsActionPanel()
    {
        $fields = FieldList::create();
        $showFields = array(
            'CanViewType'  => '',
            'ViewerGroups' => 'hide',
            'CanEditType'  => '',
            'EditorGroups' => 'hide',
        );
        /** @var SiteTree $siteTree */
        $siteTree = singleton(SiteTree::class);
        $settingsFields = $siteTree->getSettingsFields();

        foreach ($showFields as $name => $extraCss) {
            $compositeName = "Root.Settings.$name";
            /** @var FormField $field */
            if ($field = $settingsFields->fieldByName($compositeName)) {
                $field->addExtraClass($extraCss);
                $title = str_replace('page', 'document', $field->Title());
                $field->setTitle($title);

                // Remove Inherited source option from DropdownField
                if ($field instanceof DropdownField) {
                    $options = $field->getSource();
                    unset($options['Inherit']);
                    $field->setSource($options);
                }
                $fields->push($field);
            }
        }

        $this->extend('updatePermissionsFields', $fields);

        return CompositeField::create($fields);
    }


    public function onBeforeWrite()
    {
        // Set user fields
        if ($currentUserID = Member::currentUserID()) {
            if (!$this->CreatedByID) {
                $this->CreatedByID = $currentUserID;
            }
            $this->LastEditedByID = $currentUserID;
        }

        if ($this->TempFileID) {
            $file = File::get()->byID($this->TempFileID);
            $doNotCopy = $this->Config()->do_not_copy;
            $onlyCopyIfEmpty = $this->Config()->only_copy_if_empty;
            if ($file && $file->exists()) {
                $cols = $file->toMap();
                foreach ($cols as $col => $val) {
                    if (in_array($col, $doNotCopy)) {
                        //do nothing
                    } elseif (in_array($col, $onlyCopyIfEmpty)) {
                        //check column in current record is empty before copying
                        if (!$this->$col) {
                            //copy value from File record to DMSDocument record
                            $this->$col = $val;
                        }
                    } else {
                        //copy value from File record to DMSDocument record
                        $this->$col = $val;
                    }
                }
                $this->TempFileID = 0;
                $siteConfig = SiteConfig::current_site_config();
                if ($siteConfig->DMSFolder() && $siteConfig->DMSFolder()->exists()) {
                    $this->ParentID = $siteConfig->DMSFolderID;
                }
                //delete the old file records
                DB::query('DELETE FROM "File" WHERE "ID" =  ' . $file->ID);
                DB::query('DELETE FROM "File_Live" WHERE "ID" =  ' . $file->ID);
                DB::query('DELETE FROM "File_Versions" WHERE "RecordID" =  ' . $file->ID);
            }
        }

        parent::onBeforeWrite();
    }

    /**
     * Return the relative URL of an icon for the file type, based on the
     * {@link appCategory()} value.
     *
     * Images are searched for in "dms/images/app_icons/".
     *
     * @return string
     */
    public function Icon($ext)
    {
        return "/resources/vendor/heyday/silverstripe-dms/client/images/app_icons/{$ext}_32.png";
    }

    public function Link($versionID = 'latest')
    {
        return $this->getLink($versionID);
    }

    /**
     * Returns a link to download this DMSDocument from the DMS store
     * @return String
     */
    public function getLink($versionID = 'latest')
    {
        $linkID = $this->ID;
        if ($this->OriginalDMSDocumentIDFile) {
            $linkID = $this->OriginalDMSDocumentIDFile;
            $versionID = '';
        }
        $urlSegment = sprintf('%d-%s', $linkID, URLSegmentFilter::create()->filter($this->getTitle()));

        $result = Controller::join_links(Director::baseURL(), 'dmsdocument', $urlSegment, $versionID);

        $this->extend('updateGetLink', $result);

        return $result;
    }

    /**
     * Return the extension of the file associated with the document
     *
     * @return string
     */
    public function getExtension()
    {
        return strtolower(pathinfo($this->Filename, PATHINFO_EXTENSION));
    }


    public function getIdAndTitle(): string
    {
        return $this->ID . ' - ' . $this->Title;
    }

    /**
     * @return FieldList
     */
    protected function getFieldsForFile()
    {
        $extension = $this->getExtension();

        $previewField = LiteralField::create(
            "ImageFull",
            '<img id="thumbnailImage" class="thumbnail-preview" src="' . $this->Icon($extension) . '"" alt="' . $this->Title . '"/>'
        );

        //count the number of pages this document is published on
        $publishedOnCount = $this->getRelatedPages()->count();
        $publishedOnValue = "$publishedOnCount pages";
        if ($publishedOnCount == 1) {
            $publishedOnValue = "$publishedOnCount page";
        }

        $fields =  CompositeField::create(
            $filePreview = CompositeField::create(
                CompositeField::create(
                    $previewField
                )->setName("FilePreviewImage")->addExtraClass('cms-file-info-preview'),
                CompositeField::create(
                    CompositeField::create(
                        new ReadonlyField("ID", "ID number" . ':', $this->ID),
                        new ReadonlyField(
                            "FileType",
                            _t('AssetTableField.TYPE', 'File type') . ':',
                            self::get_file_type($extension)
                        ),
                        $urlField = LiteralField::create(
                            'ClickableURL',
                            '<div class="form-group field readonly">
                                <label class="form__field-label">URL:</label>
                                <div class="form__field-holder">
                                    <a href="' . $this->getLink() . '" target="_blank" class="file-url">' . $this->getLink() . '</a>
                                </div>
                            </div>'
                        ),
                        ReadonlyField::create("FilenameWithoutIDField", "Filename" . ':', $this->fileName),
                        new DateField_Disabled(
                            "Created",
                            _t('AssetTableField.CREATED', 'First uploaded') . ':',
                            $this->Created
                        ),
                        new DateField_Disabled(
                            "LastEdited",
                            _t('AssetTableField.LASTEDIT', 'Last changed') . ':',
                            $this->LastEdited
                        ),
                        new ReadonlyField("PublishedOn", "Published on" . ':', $publishedOnValue)
                    )->setName('FilePreviewDataFields')
                )->setName("FilePreviewData")->addExtraClass('cms-file-info-data')
            )->setName("FilePreview")->addExtraClass('cms-file-info')
        );

        $fields->addExtraClass('dmsdocument-documentdetails');

        $this->extend('updateFieldsForFile', $fields);

        return $fields;
    }

    /**
     * Takes a file and adds it to the DMSDocument storage, replacing the
     * current file.
     *
     * @param File $file
     *
     * @return $this
     */
    public function ingestFile($file)
    {
        $this->replaceDocument($file);
        $file->delete();

        return $this;
    }

    /**
     * Get a data list of documents related to this document
     *
     * @return DataList
     */
    public function getRelatedDocuments()
    {
        $documents = $this->RelatedDocuments();

        $this->extend('updateRelatedDocuments', $documents);

        return $documents;
    }

    /**
     * Get a list of related pages for this document by going through the associated document sets
     * @return ArrayList
     */
    public function getRelatedPages()
    {
        $pages = ArrayList::create();

        foreach ($this->Sets() as $documentSet) {
            $page = $documentSet->Page();
            $pages->add($page);
        }
        $pages->removeDuplicates();

        $this->extend('updateRelatedPages', $pages);

        return $pages;
    }

    /**
     * Get a GridField for managing related documents
     *
     * @return GridField
     */
    protected function getRelatedDocumentsGridField()
    {
        $gridField = GridField::create(
            'RelatedDocuments',
            _t('DMSDocument.RELATEDDOCUMENTS', 'Related Documents'),
            $this->RelatedDocuments(),
            new GridFieldConfig_RelationEditor
        );

        $gridFieldConfig = $gridField->getConfig();

        $gridField->getConfig()->removeComponentsByType(GridFieldAddNewButton::class);
        // Move the autocompleter to the left
        $gridField->getConfig()->removeComponentsByType(GridFieldAddExistingAutocompleter::class);
        $gridField->getConfig()->addComponent(
            $addExisting = new GridFieldAddExistingAutocompleter('buttons-before-left')
        );

        // Ensure that current document doesn't get returned in the autocompleter
        $addExisting->setSearchList($this->getRelatedDocumentsForAutocompleter());

        // Restrict search fields to specific fields only
        $addExisting->setSearchFields(array('Title:PartialMatch', 'Filename:PartialMatch'));
        $addExisting->setResultsFormat('$Filename');

        $this->extend('updateRelatedDocumentsGridField', $gridField);
        return $gridField;
    }

    /**
     * Get the list of documents to show in "related documents". This can be modified via the extension point, for
     * example if you wanted to exclude embargoed documents or something similar.
     *
     * @return DataList
     */
    protected function getRelatedDocumentsForAutocompleter()
    {
        $documents = DMSDocument::get()->exclude('ID', $this->ID);
        $this->extend('updateRelatedDocumentsForAutocompleter', $documents);
        return $documents;
    }

    /**
     * Checks at least one group is selected if CanViewType || CanEditType == 'OnlyTheseUsers'
     *
     * @return ValidationResult
     */
    public function validate()
    {
        $valid = parent::validate();

        if ($this->CanViewType == 'OnlyTheseUsers' && !$this->ViewerGroups()->count()) {
            $valid->error(
                _t(
                    'DMSDocument.VALIDATIONERROR_NOVIEWERSELECTED',
                    "Selecting 'Only these people' from a viewers list needs at least one group selected."
                )
            );
        }

        if ($this->CanEditType == 'OnlyTheseUsers' && !$this->EditorGroups()->count()) {
            $valid->error(
                _t(
                    'DMSDocument.VALIDATIONERROR_NOEDITORSELECTED',
                    "Selecting 'Only these people' from a editors list needs at least one group selected."
                )
            );
        }

        return $valid;
    }

    /**
     * Returns a reason as to why this document cannot be viewed.
     *
     * @return string
     */
    public function getPermissionDeniedReason()
    {
        $result = '';

        if ($this->CanViewType == 'LoggedInUsers') {
            $result = _t('DMSDocument.PERMISSIONDENIEDREASON_LOGINREQUIRED', 'Please log in to view this document');
        }

        if ($this->CanViewType == 'OnlyTheseUsers') {
            $result = _t(
                'DMSDocument.PERMISSIONDENIEDREASON_NOTAUTHORISED',
                'You are not authorised to view this document'
            );
        }

        return $result;
    }

    /**
     * Add an "action panel" task
     *
     * @param  string $panelKey
     * @param  string $title
     * @return $this
     */
    public function addActionPanelTask($panelKey, $title)
    {
        $this->actionTasks[$panelKey] = $title;
        return $this;
    }


    /**
     * Removes an "action panel" tasks
     *
     * @param  string $panelKey
     * @return $this
     */
    public function removeActionPanelTask($panelKey)
    {
        if (array_key_exists($panelKey, $this->actionTasks)) {
            unset($this->actionTasks[$panelKey]);
        }
        return $this;
    }

    /**
     * Takes a File object or a String (path to a file) and copies it into the DMS, replacing the original document file
     * but keeping the rest of the document unchanged.
     * @param $file File object, or String that is path to a file to store
     * @return DMSDocumentInstance Document object that we replaced the file in
     */
    public function replaceDocument($file)
    {
        return $file;
    }

    /**
     * Return a title to use on the frontend, preferably the "title", otherwise the filename without it's numeric ID
     *
     * @return string
     */
    public function getTitle()
    {
        if ($this->getField('Title')) {
            return $this->getField('Title');
        }
        return $this->FilenameWithoutID;
    }

    /**
     * Returns the Description field with HTML <br> tags added when there is a
     * line break.
     *
     * @return string
     */
    public function getDescriptionWithLineBreak()
    {
        return nl2br($this->getField('Description'));
    }

    public function canCreate($member = null, $context = [])
    {
        return $this->canEdit($member);
    }

    /**
     * DataObject edit permissions
     * @param Member $member
     * @return boolean
     */
    public function canEdit($member = null)
    {
        if (Controller::curr() instanceof DMSDocumentAdmin || Controller::curr() instanceof CMSPageEditController){
            return parent::canEdit($member);
        } else {
            return false;
        }
    }

    /**
     * see: https://github.com/silverstripe/silverstripe-framework/issues/9129
     * DataObject delete permissions
     * @param Member $member
     * @return boolean
     */
    public function canDelete($member = null)
    {
        return $this->canEdit($member);
    }

    public function CMSEditLink()
    {
        $editor = Injector::inst()->get(DMSDocumentAdmin::class);
        $cleanClass = str_replace('\\', '-', self::class);
        return $editor->Link('/' . $cleanClass . '/EditForm/field/' . $cleanClass . '/item/' . $this->ID . '/edit');
    }

    public function CMSAddLink()
    {
        $editor = Injector::inst()->get(DMSDocumentAdmin::class);
        $cleanClass = str_replace('\\', '-', self::class);
        return $editor->Link('/' . $cleanClass . '/EditForm/field/' . $cleanClass . '/item/new');
    }
}
