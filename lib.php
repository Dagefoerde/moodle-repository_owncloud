<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * ownCloud repository plugin library.
 *
 * @package    repository_owncloud
 * @copyright  2017 Project seminar (Learnweb, University of Münster)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or
 */

use repository_owncloud\ocs_client;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/repository/lib.php');
/**
 * ownCloud repository class.
 *
 * @package    repository_owncloud
 * @copyright  2017 Project seminar (Learnweb, University of Münster)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_owncloud extends repository {

    /**
     * OAuth 2 client
     * @var \core\oauth2\client
     */
    private $client = null;
    /**
     * OAuth 2 Issuer
     * @var \core\oauth2\issuer
     */
    private $issuer = null;

    /**
     * Additional scopes needed for the repository. Currently, ownCloud does not actually support/use scopes, so
     * this is intended as a hint at required functionality and will help declare future scopes.
     */
    const SCOPES = 'files ocs';

    /**
     * owncloud_client webdav client which is used for webdav operations.
     * @var \repository_owncloud\owncloud_client
     */
    private $dav = null;
    /**
     * OCS client that uses the Open Collaboration Services REST API.
     * @var ocs_client
     */
    private $ocsclient;

    /**
     * repository_owncloud constructor.
     * @param int $repositoryid
     * @param bool|int|stdClass $context
     * @param array $options
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        parent::__construct($repositoryid, $context, $options);
        try {
            // Issuer from repository instance config.
            $issuerid = $this->get_option('issuerid');
            $this->issuer = \core\oauth2\api::get_issuer($issuerid);
        } catch (dml_missing_record_exception $e) {
            // A repository is marked as disabled when no issuer is present.
            $this->disabled = true;
            return;
        }

        try {
            // Check the webdavendpoint.
            $this->parse_endpoint_url('webdav');
        } catch (\repository_owncloud\configuration_exception $e) {
            // A repository is marked as disabled when no webdav_endpoint is present
            // or it fails to parse, because all operations concerning files
            // rely on the webdav endpoint.
            $this->disabled = true;
            return;
        }

        if (!$this->issuer) {
            $this->disabled = true;
            return;
        } else if (!$this->issuer->get('enabled')) {
            // In case the Issuer is not enabled, the repository is disabled.
            $this->disabled = true;
            return;
        } else if (!self::is_valid_issuer($this->issuer)) {
            // Check if necessary endpoints are present.
            $this->disabled = true;
            return;
        }

        // Exclusively when a issuer is present and the plugin is not disabled the webdavclient is generated.
        $this->dav = $this->initiate_webdavclient();
        $this->ocsclient = new ocs_client($this->get_user_oauth_client());
    }


    /**
     * Initiates the webdav client.
     * @throws \repository_owncloud\configuration_exception If configuration is missing (endpoints).
     */
    public function initiate_webdavclient() {
        $webdavendpoint = $this->parse_endpoint_url('webdav');

        // Selects the necessary information (port, type, server) from the path to build the webdavclient.
        $server = $webdavendpoint['host'];
        if ($webdavendpoint['scheme'] === 'https') {
            $webdavtype = 'ssl://';
            $webdavport = 443;
        } else if ($webdavendpoint['scheme'] === 'http') {
            $webdavtype = '';
            $webdavport = 80;
        }

        // Override default port, if a specific one is set.
        if (isset($webdavendpoint['port'])) {
            $webdavport = $webdavendpoint['port'];
        }

        // Authentication method is `bearer` for OAuth 2. Pass oauth client from which WebDAV obtains the token when needed.
        $dav = new repository_owncloud\owncloud_client($server, '', '', 'bearer', $webdavtype,
            $this->get_user_oauth_client(), $webdavendpoint['path']);

        $dav->port = $webdavport;
        $dav->debug = false;
        return $dav;
    }

    /**
     * Check if an issuer provides all endpoints that we require.
     * @param \core\oauth2\issuer $issuer An issuer.
     * @return bool True, if all endpoints exist; false otherwise.
     */
    private static function is_valid_issuer($issuer) {
        $endpointwebdav = false;
        $endpointocs = false;
        $endpointtoken = false;
        $endpointauth = false;
        $endpoints = \core\oauth2\api::get_endpoints($issuer);
        foreach ($endpoints as $endpoint) {
            $name = $endpoint->get('name');
            switch ($name) {
                case 'webdav_endpoint':
                    $endpointwebdav = true;
                    break;
                case 'ocs_endpoint':
                    $endpointocs = true;
                    break;
                case 'token_endpoint':
                    $endpointtoken = true;
                    break;
                case 'authorization_endpoint':
                    $endpointauth = true;
                    break;
            }
        }
        return $endpointwebdav && $endpointocs && $endpointtoken && $endpointauth;
    }

    /**
     * This function does exactly the same as in the WebDAV repository. The only difference is, that
     * the ownCloud OAuth2 client uses OAuth2 instead of Basic Authentication.
     *
     * @param string $url relative path to the file.
     * @param string $title title of the file.
     * @return array|bool returns either the moodle path to the file or false.
     */
    public function get_file($url, $title = '') {
        $url = urldecode($url);
        // Prepare a file with an arbitrary name - cannot be $title because of special chars (cf. MDL-57002).
        $path = $this->prepare_file(uniqid());
        if (!$this->dav->open()) {
            return false;
        }
        $this->dav->get_file($url, $path);
        $this->dav->close();

        return array('path' => $path);
    }

    /**
     * This plugin does not support global search.
     *
     * @return bool Always false, as global search is unsupported.
     */
    public function global_search() {
        return false;
    }

    /**
     * This function does exactly the same as in the WebDAV repository. The only difference is, that
     * the ownCloud OAuth2 client uses OAuth2 instead of Basic Authentication.
     *
     * @param string $path relative path to the directory or file.
     * @param string $page page number (given multiple pages of elements).
     * @return array directory properties.
     */
    public function get_listing($path='', $page = '') {
        global $OUTPUT;

        $ret = [
            // Fetch the list dynamically. An AJAX request is sent to the server as soon as the user opens a folder.
            'dynload' => true,
            'nosearch' => true, // Disable search.
            'nologin' => false, // Provide a login link because a user logs into his/her private ownCloud storage.
            'path' => array([ // Contains all parent paths to the current path.
                'name' => get_string('owncloud', 'repository_owncloud'),
                'path' => '',
            ]),
            'list' => array(), // Contains all file/folder information and is required to build the file/folder tree.
        ];

        // Before any WebDAV method can be executed, a WebDAV client socket needs to be opened
        // which connects to the server.
        if (!$this->dav->open()) {
            return $ret;
        }

        if (empty($path) || $path == '/') {
            $path = '/';
        } else {
            // This calculates all the parents paths form the current path. This is shown in the
            // navigation bar of the file picker.
            $chunks = preg_split('|/|', trim($path, '/'));
            // Every sub-path to the last part of the current path, is a parent path.
            for ($i = 0; $i < count($chunks); $i++) {
                $ret['path'][] = array(
                    'name' => urldecode($chunks[$i]),
                    'path' => '/'. join('/', array_slice($chunks, 0, $i + 1)). '/'
                );
            }
        }

        // Since the paths, which are received from the PROPFIND WebDAV method are url encoded
        // (because they depict actual web-paths), the received paths need to be decoded back
        // for the plugin to be able to work with them.
        $dir = $this->dav->ls(urldecode($path));
        $this->dav->close();

        // The method get_listing return all information about all child files/folders of the
        // current directory. If no information was received, the directory must be empty.
        if (!is_array($dir)) {
            return $ret;
        }
        $folders = array();
        $files = array();
        // TODO (#6) handle (base)path in client, not here.
        $parsedurl = $this->parse_endpoint_url('webdav');
        $webdavpath = rtrim('/'.ltrim($parsedurl['path'], '/ '), '/ ');
        foreach ($dir as $v) {
            if (!empty($v['lastmodified'])) {
                $v['lastmodified'] = strtotime($v['lastmodified']);
            } else {
                $v['lastmodified'] = null;
            }
            // TODO there must be a better way... /remote.php/webdav/ is always present.
            // Extracting object title from absolute path.
            $v['href'] = substr(urldecode($v['href']), strlen($webdavpath));
            $title = substr($v['href'], strlen($path));

            if (!empty($v['resourcetype']) && $v['resourcetype'] == 'collection') {
                // A folder.
                if ($path != $v['href']) {
                    $folders[strtoupper($title)] = array(
                        'title' => rtrim($title, '/'),
                        'thumbnail' => $OUTPUT->image_url(file_folder_icon(90))->out(false),
                        'children' => array(),
                        'datemodified' => $v['lastmodified'],
                        'path' => $v['href']
                    );
                }
            } else {
                // A file.
                $size = !empty($v['getcontentlength']) ? $v['getcontentlength'] : '';
                $files[strtoupper($title)] = array(
                    'title' => $title,
                    'thumbnail' => $OUTPUT->image_url(file_extension_icon($title, 90))->out(false),
                    'size' => $size,
                    'datemodified' => $v['lastmodified'],
                    'source' => $v['href']
                );
            }
        }
        ksort($files);
        ksort($folders);
        $ret['list'] = array_merge($folders, $files);
        return $ret;

    }

    /**
     * Use OCS to generate a public share to the requested file.
     * This method derives a download link from the public share URL.
     *
     * @param string $url relative path to the chosen file
     * @return string the generated download link.
     * @throws \repository_owncloud\request_exception If ownCloud responded badly
     *
     */
    public function get_link($url) {
        $ocsparams = [
            'path' => $url,
            'shareType' => ocs_client::SHARE_TYPE_PUBLIC,
            'publicUpload' => false,
            'permissions' => ocs_client::SHARE_PERMISSION_READ
            ];

        $response = $this->ocsclient->call('create_share', $ocsparams);
        $xml = simplexml_load_string($response);

        if ($xml === false ) {
            throw new \repository_owncloud\request_exception('Invalid response');
        }

        if ((string)$xml->meta->status !== 'ok') {
            throw new \repository_owncloud\request_exception(
                sprintf('(%s) %s', $xml->meta->statuscode, $xml->meta->message));
        }

        // Take the share link and convert it into a download link.
        return ((string)$xml->data[0]->url) . '/download';
    }

    /**
     * This method converts the source from the file picker (chosen by the user) into
     * information, which will be received by methods that fetch files/references from
     * the ownCloud server.
     *
     * @param string $source source of the file, returned by repository as 'source' and received back from user (not cleaned)
     * @return string file reference, ready to be stored
     */
    public function get_file_reference($source) {
        $usefilereference = optional_param('usefilereference', false, PARAM_BOOL);

        $reference = $source;

        // If a filereference was requested, a public link to the file has to be generated and returned.
        if ($usefilereference) {
            $reference = $this->get_link($source);
        }

        // Otherwise, the simple relative path to the file is enough.
        return $reference;
    }

    /**
     * Repository method that serves the referenced file (created e.g. via get_link).
     * All parameters are there for compatibility with superclass, but they are ignored.
     *
     * @param stored_file $storedfile (ignored)
     * @param int $lifetime (ignored)
     * @param int $filter (ignored)
     * @param bool $forcedownload (ignored)
     * @param array $options (ignored)
     */
    public function send_file($storedfile, $lifetime=86400 , $filter=0, $forcedownload=false, array $options = null) {
        // Delivers a download link to the concerning file.
        redirect($storedfile->get_reference());
    }

    /**
     * Function which checks whether the user is logged in on the ownCloud instance.
     *
     * @return bool false, if no Access Token is set or can be requested.
     */
    public function check_login() {
        $client = $this->get_user_oauth_client();
        return $client->is_logged_in();
    }

    /**
     * Get a cached user authenticated oauth client.
     * @param bool|moodle_url $overrideurl Use this url instead of the repo callback.
     * @return \core\oauth2\client
     */
    protected function get_user_oauth_client($overrideurl = false) {
        if ($this->client) {
            return $this->client;
        }
        // TODO $overrideurl is not used currently. GDocs uses it in send_file. Evaluate whether we need it.
        if ($overrideurl) {
            $returnurl = $overrideurl;
        } else {
            $returnurl = new moodle_url('/repository/repository_callback.php');
            $returnurl->param('callback', 'yes');
            $returnurl->param('repo_id', $this->id);
            $returnurl->param('sesskey', sesskey());
        }
        $this->client = \core\oauth2\api::get_user_oauth_client($this->issuer, $returnurl, self::SCOPES);
        return $this->client;
    }

    /**
     * Prints a simple Login Button which redirects to an authorization window from ownCloud.
     *
     * @return mixed login window properties.
     */
    public function print_login() {
        $client = $this->get_user_oauth_client();
        $loginurl = $client->get_login_url();
        if ($this->options['ajax']) {
            $ret = array();
            $btn = new \stdClass();
            $btn->type = 'popup';
            $btn->url = $loginurl->out(false);
            $ret['login'] = array($btn);
            return $ret;
        } else {
            echo html_writer::link($loginurl, get_string('login', 'repository'),
                    array('target' => '_blank',  'rel' => 'noopener noreferrer'));
        }
    }

    /**
     * Deletes the held Access Token and prints the Login window.
     *
     * @return array login window properties.
     */
    public function logout() {
        $client = $this->get_user_oauth_client();
        $client->log_out();
        return parent::logout();
    }

    /**
     * Sets up access token after the redirection from ownCloud.
     * The Moodle OAuth 2 API transfers Client ID and secret as params in the request.
     * However, the ownCloud OAuth 2 App expects Client ID and secret to be in the request header.
     * Therefore, the header is set beforehand, and ClientID and Secret are passed twice.
     */
    public function callback() {
        $client = $this->get_user_oauth_client();
        // If an Access Token is stored within the client, it has to be deleted to prevent the addition
        // of an Bearer authorization header in the request method.
        $client->log_out();
        $client->setHeader(array(
            'Authorization: Basic ' . base64_encode($client->get_clientid() . ':' . $client->get_clientsecret())
        ));
        // This will upgrade to an access token if we have an authorization code and save the access token in the session.
        $client->is_logged_in();
    }

    /**
     * Create an instance for this plug-in
     *
     * @param string $type the type of the repository
     * @param int $userid the user id
     * @param stdClass $context the context
     * @param array $params the options for this instance
     * @param int $readonly whether to create it readonly or not (defaults to not)
     * @return mixed
     */
    public static function create($type, $userid, $context, $params, $readonly=0) {
        require_capability('moodle/site:config', context_system::instance());
        return parent::create($type, $userid, $context, $params, $readonly);
    }

    /**
     * This method adds a select form and additional information to the settings form..
     *
     * @param \moodleform $mform Moodle form (passed by reference)
     */
    public static function instance_config_form($mform) {
        if (!has_capability('moodle/site:config', context_system::instance())) {
            $mform->addElement('static', null, '',  get_string('nopermissions', 'error', get_string('configplugin',
                'repository_owncloud')));
            return false;
        }

        // Load configured issuers.
        $issuers = core\oauth2\api::get_all_issuers();
        $types = array();

        // Validates which issuers implement the right endpoints. WebDav is necessary for ownCloud.
        $validissuers = [];
        foreach ($issuers as $issuer) {
            $types[$issuer->get('id')] = $issuer->get('name');
            if (self::is_valid_issuer($issuer)) {
                $validissuers[] = $issuer->get('name');
            }
        }

        // Render the form.
        $url = new \moodle_url('/admin/tool/oauth2/issuers.php');
        $mform->addElement('static', null, '', get_string('oauth2serviceslink', 'repository_owncloud', $url->out()));

        $mform->addElement('select', 'issuerid', get_string('chooseissuer', 'repository_owncloud'), $types);
        $mform->addRule('issuerid', get_string('required'), 'required', null, 'issuer');
        $mform->addHelpButton('issuerid', 'chooseissuer', 'repository_owncloud');
        $mform->setType('issuerid', PARAM_INT);

        // All issuers that are valid are displayed seperately (if any).
        if (count($validissuers) === 0) {
            $mform->addElement('html', get_string('no_right_issuers', 'repository_owncloud'));
        } else {
            $mform->addElement('html', get_string('right_issuers', 'repository_owncloud', implode(', ', $validissuers)));
        }
    }

    /**
     * Save settings for repository instance
     *
     * @param array $options settings
     * @return bool
     */
    public function set_option($options = array()) {
        $options['issuerid'] = clean_param($options['issuerid'], PARAM_INT);
        $ret = parent::set_option($options);
        return $ret;
    }

    /**
     * Names of the plugin settings
     *
     * @return array
     */
    public static function get_instance_option_names() {
        return ['issuerid'];
    }

    /**
     * Method to define which filetypes are supported (hardcoded can not be changed in Admin Menu)
     *
     * For a full list of possible types and groups, look in lib/filelib.php, function get_mimetypes_array()
     *
     * @return string '*' means this repository support any files
     */
    public function supported_filetypes() {
        return '*';
    }

    /**
     * Method to define which Files are supported (hardcoded can not be changed in Admin Menu)
     * Now only FILE_INTERNAL since get_link and get_file_reference is not implemented.
     * Can choose FILE_REFERENCE|FILE_INTERNAL|FILE_EXTERNAL
     * FILE_INTERNAL - the file is uploaded/downloaded and stored directly within the Moodle file system
     * FILE_EXTERNAL - the file stays in the external repository and is accessed from there directly
     * FILE_REFERENCE - the file may be cached locally, but is automatically synchronised, as required,
     *                 with any changes to the external original
     * @return int return type bitmask supported
     */
    public function supported_returntypes() {
        return FILE_INTERNAL | FILE_EXTERNAL | FILE_REFERENCE;
    }

    /**
     * Returns the parsed url of the choosen endpoint.
     * @param string $endpointname
     * @return array parseurl [scheme => https/http, host=>'hostname', port=>443, path=>'path']
     * @throws \repository_owncloud\configuration_exception if an endpoint is undefined
     */
    private function parse_endpoint_url($endpointname) {
        $url = $this->issuer->get_endpoint_url($endpointname);
        if (empty($url)) {
            throw new \repository_owncloud\configuration_exception(sprintf('Endpoint %s not defined.', $endpointname));
        }
        return parse_url($url);
    }
}