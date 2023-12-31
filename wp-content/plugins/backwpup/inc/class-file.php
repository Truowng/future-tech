<?php
/**
 * Class for methods for file/folder related things.
 *
 * @todo Please split this logic into two separated classes. One for File and another for dir.
 */
class BackWPup_File
{
    /**
     * Get the folder for blog uploads.
     *
     * @return string
     */
    public static function get_upload_dir()
    {
        if (is_multisite()) {
            if (defined('UPLOADBLOGSDIR')) {
                return trailingslashit(str_replace('\\', '/', ABSPATH . UPLOADBLOGSDIR));
            }
            if (is_dir(trailingslashit(WP_CONTENT_DIR) . 'uploads/sites')) {
                return str_replace('\\', '/', trailingslashit(WP_CONTENT_DIR) . 'uploads/sites/');
            }
            if (is_dir(trailingslashit(WP_CONTENT_DIR) . 'uploads')) {
                return str_replace('\\', '/', trailingslashit(WP_CONTENT_DIR) . 'uploads/');
            }

            return trailingslashit(str_replace('\\', '/', (string) WP_CONTENT_DIR));
        }
        $upload_dir = wp_upload_dir(null, false, true);

        return trailingslashit(str_replace('\\', '/', $upload_dir['basedir']));
    }

    /**
     * check if path in open basedir.
     *
     * @param string $file the file path to check
     *
     * @return bool is it in open basedir
     */
    public static function is_in_open_basedir($file)
    {
        $ini_open_basedir = ini_get('open_basedir');

        if (empty($ini_open_basedir)) {
            return true;
        }

        $open_base_dirs = explode(PATH_SEPARATOR, $ini_open_basedir);
        $file = trailingslashit(strtolower(str_replace('\\', '/', $file)));

        foreach ($open_base_dirs as $open_base_dir) {
            if (empty($open_base_dir) || !realpath($open_base_dir)) {
                continue;
            }
            $open_base_dir = realpath($open_base_dir);
            $open_base_dir = strtolower(str_replace('\\', '/', $open_base_dir));
            $part = substr($file, 0, strlen($open_base_dir));
            if ($part === $open_base_dir) {
                return true;
            }
        }

        return false;
    }

    /**
     * get size of files in folder.
     *
     * @param string $folder the folder to calculate
     * @param bool   $deep   went thrue suborders
     *
     * @return int folder size in byte
     */
    public static function get_folder_size($folder)
    {
        $files_size = 0;

        if (!is_readable($folder)) {
            return $files_size;
        }

        $iterator = new RecursiveIteratorIterator(new BackWPup_Recursive_Directory($folder, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file->isLink()) {
                $files_size += $file->getSize();
            }
        }

        return $files_size;
    }

    /**
     * Get an absolute path if it is relative.
     *
     * @param string $path
     *
     * @return string
     */
    public static function get_absolute_path($path = '/')
    {
        $path = str_replace('\\', '/', $path);
        $content_path = trailingslashit(str_replace('\\', '/', (string) WP_CONTENT_DIR));

        //use WP_CONTENT_DIR as root folder
        if (empty($path) || $path === '/') {
            $path = $content_path;
        }

        //make relative path to absolute
        if (substr($path, 0, 1) !== '/' && !preg_match('#^[a-zA-Z]:/#', $path)) {
            $path = $content_path . $path;
        }

        return self::resolve_path($path);
    }

    /**
     * Check is folder readable and exists create it if not
     * add .htaccess or index.html file in folder to prevent directory listing.
     *
     * @param string $folder      the folder to check
     * @param bool   $donotbackup Create a file that the folder will not backuped
     *
     * @return string with error message if one
     */
    public static function check_folder($folder, $donotbackup = false)
    {
        $folder = self::get_absolute_path($folder);
        $folder = untrailingslashit($folder);

        //check that is not home of WP
        $uploads = self::get_upload_dir();
        if ($folder === untrailingslashit(str_replace('\\', '/', (string) ABSPATH))
             || $folder === untrailingslashit(str_replace('\\', '/', dirname((string) ABSPATH)))
             || $folder === untrailingslashit(str_replace('\\', '/', (string) WP_PLUGIN_DIR))
             || $folder === untrailingslashit(str_replace('\\', '/', (string) WP_CONTENT_DIR))
             || $folder === untrailingslashit($uploads)
             || $folder === '/'
        ) {
            return sprintf(__('Folder %1$s not allowed, please use another folder.', 'backwpup'), $folder);
        }

        //open base dir check
        if (!self::is_in_open_basedir($folder)) {
            return sprintf(__('Folder %1$s is not in open basedir, please use another folder.', 'backwpup'), $folder);
        }

        //create folder if it not exists
        if (!is_dir($folder)) {
            if (!wp_mkdir_p($folder)) {
                return sprintf(__('Cannot create folder: %1$s', 'backwpup'), $folder);
            }
        }

        //check is writable dir
        if (!is_writable($folder)) {
            return sprintf(__('Folder "%1$s" is not writable', 'backwpup'), $folder);
        }

        //create files for securing folder
        if (get_site_option('backwpup_cfg_protectfolders')) {
            $server_software = strtolower((string) $_SERVER['SERVER_SOFTWARE']);
            //IIS
            if (strstr($server_software, 'microsoft-iis')) {
                if (!file_exists($folder . '/Web.config')) {
                    file_put_contents(
                        $folder . '/Web.config',
                        '<configuration>' . PHP_EOL .
                        "\t<system.webServer>" . PHP_EOL .
                        "\t\t<authorization>" . PHP_EOL .
                        "\t\t\t<deny users=\"*\" />" . PHP_EOL .
                        "\t\t</authorization>" . PHP_EOL .
                        "\t</system.webServer>" . PHP_EOL .
                        '</configuration>'
                    );
                }
            } //Nginx
            elseif (strstr($server_software, 'nginx')) {
                if (!file_exists($folder . '/index.php')) {
                    file_put_contents($folder . '/index.php', '<?php' . PHP_EOL . "header( \$_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found' );" . PHP_EOL . "header( 'Status: 404 Not Found' );" . PHP_EOL);
                }
            } //Aapche and other
            else {
                if (!file_exists($folder . '/.htaccess')) {
                    file_put_contents($folder . '/.htaccess', '<Files "*">' . PHP_EOL . '<IfModule mod_access.c>' . PHP_EOL . 'Deny from all' . PHP_EOL . '</IfModule>' . PHP_EOL . '<IfModule !mod_access_compat>' . PHP_EOL . '<IfModule mod_authz_host.c>' . PHP_EOL . 'Deny from all' . PHP_EOL . '</IfModule>' . PHP_EOL . '</IfModule>' . PHP_EOL . '<IfModule mod_access_compat>' . PHP_EOL . 'Deny from all' . PHP_EOL . '</IfModule>' . PHP_EOL . '</Files>');
                }
                if (!file_exists($folder . '/index.php')) {
                    file_put_contents($folder . '/index.php', '<?php' . PHP_EOL . "header( \$_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found' );" . PHP_EOL . "header( 'Status: 404 Not Found' );" . PHP_EOL);
                }
            }
        }

        //Create do not backup file for this folder
        if ($donotbackup && !file_exists($folder . '/.donotbackup')) {
            file_put_contents($folder . '/.donotbackup', __('BackWPup will not backup folders and its sub folders when this file is inside.', 'backwpup'));
        }

        return '';
    }

    /**
     * Resolve internal .. within a path.
     *
     * @param string $path The path to resolve
     *
     * @return string The resolved path
     */
    protected static function resolve_path($path)
    {
        $search = explode('/', $path);
        $append = [];
        // If last element of $search is blank, this means trailing slash is present.
        // realpath() will remove trailing slash, so append to $append to preserve.
        if (empty($search[count($search) - 1])) {
            array_unshift($append, array_pop($search));
        }

        while (realpath(implode('/', $search)) === false) {
            array_unshift($append, array_pop($search));
        }

        $path = realpath(implode('/', $search));
        if (!empty($append)) {
            $path .= '/' . implode('/', $append);
        }

        return $path;
    }
}
