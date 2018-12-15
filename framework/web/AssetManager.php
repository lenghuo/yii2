<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\web;

use Yii;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\helpers\Url;

/**
 * AssetManager 管理资源包的配置和加载。
 *
 * AssetManager 已经默认在 [[\yii\web\Application]] 里配置到了应用配置。
 * 你可以通过 `Yii::$app->assetManager` 访问该实例
 *
 * 你仍可以修改其配置，在应用配置的 `components` 里添加数组,
 * 就像这样：
 *
 * ```php
 * 'assetManager' => [
 *     'bundles' => [
 *         // 在这里重新配置资源包
 *     ],
 * ]
 * ```
 *
 * 关于 AssetManager 的更多使用参考，请查看 [前端资源](guide:structure-assets)。
 *
 * @property AssetConverterInterface $converter 资源编译器。请注意此属性的
 * getter 和 setter 上的不同。具体细节请查看 [[getConverter()]] 和 [[setConverter()]] 方法。
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class AssetManager extends Component
{
    /**
     * @var array|bool 资源包配置列表。提供此属性是为了自定义资源包。
     * 在 [[getBundle()]] 方法里，当一个资源包被加载，如果它在此处有相应的配置，
     * 这些配置将应用于这个资源包。
     *
     * 数组的键是资源包名称，通常是资源包类名，没有反斜杠的那种。
     * 数组的值是相应的配置，如果值为 false，则意味这it means the corresponding asset
     * 这个资源包被禁用，[[getBundle()]] 返回为 null。
     *
     * 如果此属性为 false，则意味全部的资源包都被禁用，
     * [[getBundle()]] 会全部返回 null。
     *
     * 以下示例显示如何禁用 Bootstrap 小部件去使用 bootstrap 的 CSS 。
     * （由于你想使用自己的样式）：
     *
     * ```php
     * [
     *     'yii\bootstrap\BootstrapAsset' => [
     *         'css' => [],
     *     ],
     * ]
     * ```
     */
    public $bundles = [];
    /**
     * @var string 保存已发布的资源文件的根目录。
     */
    public $basePath = '@webroot/assets';
    /**
     * @var string 已发布资源文件可以访问的基链接。
     */
    public $baseUrl = '@web/assets';
    /**
     * @var array 源资源文件（键）和目标资源文件（值）的映射。
     *
     * 此属性用于支持在某些资源包中修复不正确的资源文件路径。
     * 当资源包在视图中注册时，其 [[AssetBundle::css|css]] 和 [[AssetBundle::js|js]] 中的每个相对资源文件
     * 都会被这个映射检查。如果找到相应的键，
     * 将作为资源文件的最后部分（如果可用，以 [[AssetBundle::sourcePath]] 为前缀），
     * 相应的值将替换资源，并被注册到视图中。
     * 例如，资源文件 `my/path/to/jquery.js` 匹配了 `jquery.js`。
     *
     * 请注意，目标资源文件必须为绝对 URL 、相对于域名的 URL（以“/”开头）或者是
     * 相对于 [[baseUrl]] 和 [[basePath]] 的路径。
     *
     * 在以下示例中，任何以 `jquery.min.js` 结尾的资源都会被替换成 `jquery/dist/jquery.js`，
     * 其相对路径是 [[baseUrl]] 和 [[basePath]]。
     *
     * ```php
     * [
     *     'jquery.min.js' => 'jquery/dist/jquery.js',
     * ]
     * ```
     *
     * 你还可以用别名指定映射的值，例如：
     *
     * ```php
     * [
     *     'jquery.min.js' => '@web/js/jquery/jquery.js',
     * ]
     * ```
     */
    public $assetMap = [];
    /**
     * @var bool 是否使用符号链接发布资源文件。默认为 false，意味着
     * 资源文件件被复制到 [[basePath]]。使用符号链接有这样的好处：发布的资源永远和
     * 源文件一致，并且不需要复制操作。
     * 这在开发过程中特别有用。
     *
     * 但是，使用符号链接对主机环境有特殊要求。
     * 特别是，在 Linux/Unix，和 Windows Vista/2008 或更高版本上才支持符号链接。
     *
     * 此外，需要正确配置某些 Web 服务器，以便可以访问链接过的资源能被 Web 用户访问。
     * 例如，对于 Apache Web 服务器，应添加以下的配置指令到 Web 文件夹：
     *
     *
     * ```apache
     * Options FollowSymLinks
     * ```
     */
    public $linkAssets = false;
    /**
     * @var int 新发布的资源文件的权限。
     * 此值将被 PHP 函数 chmod() 所使用。不设掩码（umask）。
     * 如果未设置，权限将由当前环境确定。
     */
    public $fileMode;
    /**
     * @var int 新创建的资源目录的权限。
     * 此值将被 PHP 函数 chmod() 所使用。不设掩码（umask）。
     * 默认值为 0775，意味着目录可以被拥有者和拥有组别读写，
     * 但是其他用户只读。
     */
    public $dirMode = 0775;
    /**
     * @var callback PHP 回调：在复制每个子目录或文件之前调用。
     * 此选项仅在发布目录时使用。如果回调返回 false，
     * 则复制子目录或文件的操作将被取消。
     *
     * 回调的形式：`function ($from, $to)`，其中 `$from` 是子目录或者
     * 要复制的文件，而 `$to` 是复制目标。
     *
     * 这个回调作为参数 `beforeCopy` 传递给 [[\yii\helpers\FileHelper::copyDirectory()]]。
     */
    public $beforeCopy;
    /**
     * @var callback PHP 回调：在复制每个子目录或文件成功之后调用。
     * 此选项仅在发布目录时使用。回调的形式和 [[beforeCopy]] 一样。
     *
     * 这个回调作为参数 `afterCopy` 传递给 [[\yii\helpers\FileHelper::copyDirectory()]]。
     */
    public $afterCopy;
    /**
     * @var bool 当目标目录已存在，正发布的目录是否应发布。
     * 此选项仅在发布目录时使用。
     * 你可能希望在开发阶段将其设置为 `true` 以确保已发布目录始终是最新的。
     * 不要在生产服务器设置此属性，
     * 它会显着降低性能。
     */
    public $forceCopy = false;
    /**
     * @var bool 是否将时间戳附加到每个已发布资源的 URL 上。
     * 如果为 true，已发布资源的 URL 就会像 `/path/to/asset?v=timestamp`，
     * 其中 `timestamp` 是已发布文件的最后修改时间。
     * 通常情况下，你为资源启用 HTTP 缓存时，可将此属性设置为 true，
     * 因为它会在你更新资源文件时刷新缓存。
     * @since 2.0.3
     */
    public $appendTimestamp = false;
    /**
     * @var callable PHP 回调：该回调函数将被调用以生成资源目录的哈希值。
     * 回调的形式如下：
     *
     * ```
     * function ($path)
     * ```
     *
     * 其中 `$path` 资源路径。请注意，`$path` 可以是资源目录，也可以是单个文件。
     * 对于在 `url()` 中使用的相对路径的 CSS 文件，
     * 哈希实现应该使用文件的目录路径而不是复制中的资源文件的相对路径。
     *
     *
     * 如果未设置，资产管理器将在 `hash` 方法中使用 CRC32 和 filemtime。
     *
     *
     * 用 MD4 哈希的一个实现例子：
     *
     * ```php
     * function ($path) {
     *     return hash('md4', $path);
     * }
     * ```
     *
     * @since 2.0.6
     */
    public $hashCallback;

    private $_dummyBundles = [];


    /**
     * 初始化组件
     * @throws InvalidConfigException 如果 [[basePath]] 无效
     */
    public function init()
    {
        parent::init();
        $this->basePath = Yii::getAlias($this->basePath);
        if (!is_dir($this->basePath)) {
            throw new InvalidConfigException("The directory does not exist: {$this->basePath}");
        } elseif (!is_writable($this->basePath)) {
            throw new InvalidConfigException("The directory is not writable by the Web process: {$this->basePath}");
        }

        $this->basePath = realpath($this->basePath);
        $this->baseUrl = rtrim(Yii::getAlias($this->baseUrl), '/');
    }

    /**
     * 返回所找的资源包对象。
     *
     * 这个方法首先会在 [[bundles]] 你查找。如找不到
     * 它会将 `$name` 当作资源包的类，并创建一个新实例。
     *
     * @param string $name 资源包的类名称（没有反斜杠前缀）
     * @param bool $publish 是否在返回资源包之前发布资源包中的资源文件。
     * 如果将此设置为 false，则必须手动调用 `AssetBundle::publish()` 来发布资源文件。
     * @return AssetBundle 资源包对象实例
     * @throws InvalidConfigException 如果 $name 没有指向任何合法资源包
     */
    public function getBundle($name, $publish = true)
    {
        if ($this->bundles === false) {
            return $this->loadDummyBundle($name);
        } elseif (!isset($this->bundles[$name])) {
            return $this->bundles[$name] = $this->loadBundle($name, [], $publish);
        } elseif ($this->bundles[$name] instanceof AssetBundle) {
            return $this->bundles[$name];
        } elseif (is_array($this->bundles[$name])) {
            return $this->bundles[$name] = $this->loadBundle($name, $this->bundles[$name], $publish);
        } elseif ($this->bundles[$name] === false) {
            return $this->loadDummyBundle($name);
        }

        throw new InvalidConfigException("Invalid asset bundle configuration: $name");
    }

    /**
     * 根据名称加载资源包。
     *
     * @param string $name 资源包名称
     * @param array $config 资源包对象的配置
     * @param bool $publish 是否发布资源包
     * @return AssetBundle
     * @throws InvalidConfigException 如果配置无效
     */
    protected function loadBundle($name, $config = [], $publish = true)
    {
        if (!isset($config['class'])) {
            $config['class'] = $name;
        }
        /* @var $bundle AssetBundle */
        $bundle = Yii::createObject($config);
        if ($publish) {
            $bundle->publish($this);
        }

        return $bundle;
    }

    /**
     * Loads dummy bundle by name.
     *
     * @param string $name
     * @return AssetBundle
     */
    protected function loadDummyBundle($name)
    {
        if (!isset($this->_dummyBundles[$name])) {
            $this->_dummyBundles[$name] = $this->loadBundle($name, [
                'sourcePath' => null,
                'js' => [],
                'css' => [],
                'depends' => [],
            ]);
        }

        return $this->_dummyBundles[$name];
    }

    /**
     * Returns the actual URL for the specified asset.
     * The actual URL is obtained by prepending either [[AssetBundle::$baseUrl]] or [[AssetManager::$baseUrl]] to the given asset path.
     * @param AssetBundle $bundle the asset bundle which the asset file belongs to
     * @param string $asset the asset path. This should be one of the assets listed in [[AssetBundle::$js]] or [[AssetBundle::$css]].
     * @return string the actual URL for the specified asset.
     */
    public function getAssetUrl($bundle, $asset)
    {
        if (($actualAsset = $this->resolveAsset($bundle, $asset)) !== false) {
            if (strncmp($actualAsset, '@web/', 5) === 0) {
                $asset = substr($actualAsset, 5);
                $basePath = Yii::getAlias('@webroot');
                $baseUrl = Yii::getAlias('@web');
            } else {
                $asset = Yii::getAlias($actualAsset);
                $basePath = $this->basePath;
                $baseUrl = $this->baseUrl;
            }
        } else {
            $basePath = $bundle->basePath;
            $baseUrl = $bundle->baseUrl;
        }

        if (!Url::isRelative($asset) || strncmp($asset, '/', 1) === 0) {
            return $asset;
        }

        if ($this->appendTimestamp && ($timestamp = @filemtime("$basePath/$asset")) > 0) {
            return "$baseUrl/$asset?v=$timestamp";
        }

        return "$baseUrl/$asset";
    }

    /**
     * Returns the actual file path for the specified asset.
     * @param AssetBundle $bundle the asset bundle which the asset file belongs to
     * @param string $asset the asset path. This should be one of the assets listed in [[AssetBundle::$js]] or [[AssetBundle::$css]].
     * @return string|false the actual file path, or `false` if the asset is specified as an absolute URL
     */
    public function getAssetPath($bundle, $asset)
    {
        if (($actualAsset = $this->resolveAsset($bundle, $asset)) !== false) {
            return Url::isRelative($actualAsset) ? $this->basePath . '/' . $actualAsset : false;
        }

        return Url::isRelative($asset) ? $bundle->basePath . '/' . $asset : false;
    }

    /**
     * @param AssetBundle $bundle
     * @param string $asset
     * @return string|bool
     */
    protected function resolveAsset($bundle, $asset)
    {
        if (isset($this->assetMap[$asset])) {
            return $this->assetMap[$asset];
        }
        if ($bundle->sourcePath !== null && Url::isRelative($asset)) {
            $asset = $bundle->sourcePath . '/' . $asset;
        }

        $n = mb_strlen($asset, Yii::$app->charset);
        foreach ($this->assetMap as $from => $to) {
            $n2 = mb_strlen($from, Yii::$app->charset);
            if ($n2 <= $n && substr_compare($asset, $from, $n - $n2, $n2) === 0) {
                return $to;
            }
        }

        return false;
    }

    private $_converter;

    /**
     * Returns the asset converter.
     * @return AssetConverterInterface the asset converter.
     */
    public function getConverter()
    {
        if ($this->_converter === null) {
            $this->_converter = Yii::createObject(AssetConverter::className());
        } elseif (is_array($this->_converter) || is_string($this->_converter)) {
            if (is_array($this->_converter) && !isset($this->_converter['class'])) {
                $this->_converter['class'] = AssetConverter::className();
            }
            $this->_converter = Yii::createObject($this->_converter);
        }

        return $this->_converter;
    }

    /**
     * Sets the asset converter.
     * @param array|AssetConverterInterface $value the asset converter. This can be either
     * an object implementing the [[AssetConverterInterface]], or a configuration
     * array that can be used to create the asset converter object.
     */
    public function setConverter($value)
    {
        $this->_converter = $value;
    }

    /**
     * @var array published assets
     */
    private $_published = [];

    /**
     * Publishes a file or a directory.
     *
     * This method will copy the specified file or directory to [[basePath]] so that
     * it can be accessed via the Web server.
     *
     * If the asset is a file, its file modification time will be checked to avoid
     * unnecessary file copying.
     *
     * If the asset is a directory, all files and subdirectories under it will be published recursively.
     * Note, in case $forceCopy is false the method only checks the existence of the target
     * directory to avoid repetitive copying (which is very expensive).
     *
     * By default, when publishing a directory, subdirectories and files whose name starts with a dot "."
     * will NOT be published. If you want to change this behavior, you may specify the "beforeCopy" option
     * as explained in the `$options` parameter.
     *
     * Note: On rare scenario, a race condition can develop that will lead to a
     * one-time-manifestation of a non-critical problem in the creation of the directory
     * that holds the published assets. This problem can be avoided altogether by 'requesting'
     * in advance all the resources that are supposed to trigger a 'publish()' call, and doing
     * that in the application deployment phase, before system goes live. See more in the following
     * discussion: http://code.google.com/p/yii/issues/detail?id=2579
     *
     * @param string $path the asset (file or directory) to be published
     * @param array $options the options to be applied when publishing a directory.
     * The following options are supported:
     *
     * - only: array, list of patterns that the file paths should match if they want to be copied.
     * - except: array, list of patterns that the files or directories should match if they want to be excluded from being copied.
     * - caseSensitive: boolean, whether patterns specified at "only" or "except" should be case sensitive. Defaults to true.
     * - beforeCopy: callback, a PHP callback that is called before copying each sub-directory or file.
     *   This overrides [[beforeCopy]] if set.
     * - afterCopy: callback, a PHP callback that is called after a sub-directory or file is successfully copied.
     *   This overrides [[afterCopy]] if set.
     * - forceCopy: boolean, whether the directory being published should be copied even if
     *   it is found in the target directory. This option is used only when publishing a directory.
     *   This overrides [[forceCopy]] if set.
     *
     * @return array the path (directory or file path) and the URL that the asset is published as.
     * @throws InvalidArgumentException if the asset to be published does not exist.
     */
    public function publish($path, $options = [])
    {
        $path = Yii::getAlias($path);

        if (isset($this->_published[$path])) {
            return $this->_published[$path];
        }

        if (!is_string($path) || ($src = realpath($path)) === false) {
            throw new InvalidArgumentException("The file or directory to be published does not exist: $path");
        }

        if (is_file($src)) {
            return $this->_published[$path] = $this->publishFile($src);
        }

        return $this->_published[$path] = $this->publishDirectory($src, $options);
    }

    /**
     * Publishes a file.
     * @param string $src the asset file to be published
     * @return string[] the path and the URL that the asset is published as.
     * @throws InvalidArgumentException if the asset to be published does not exist.
     */
    protected function publishFile($src)
    {
        $dir = $this->hash($src);
        $fileName = basename($src);
        $dstDir = $this->basePath . DIRECTORY_SEPARATOR . $dir;
        $dstFile = $dstDir . DIRECTORY_SEPARATOR . $fileName;

        if (!is_dir($dstDir)) {
            FileHelper::createDirectory($dstDir, $this->dirMode, true);
        }

        if ($this->linkAssets) {
            if (!is_file($dstFile)) {
                try { // fix #6226 symlinking multi threaded
                    symlink($src, $dstFile);
                } catch (\Exception $e) {
                    if (!is_file($dstFile)) {
                        throw $e;
                    }
                }
            }
        } elseif (@filemtime($dstFile) < @filemtime($src)) {
            copy($src, $dstFile);
            if ($this->fileMode !== null) {
                @chmod($dstFile, $this->fileMode);
            }
        }

        return [$dstFile, $this->baseUrl . "/$dir/$fileName"];
    }

    /**
     * Publishes a directory.
     * @param string $src the asset directory to be published
     * @param array $options the options to be applied when publishing a directory.
     * The following options are supported:
     *
     * - only: array, list of patterns that the file paths should match if they want to be copied.
     * - except: array, list of patterns that the files or directories should match if they want to be excluded from being copied.
     * - caseSensitive: boolean, whether patterns specified at "only" or "except" should be case sensitive. Defaults to true.
     * - beforeCopy: callback, a PHP callback that is called before copying each sub-directory or file.
     *   This overrides [[beforeCopy]] if set.
     * - afterCopy: callback, a PHP callback that is called after a sub-directory or file is successfully copied.
     *   This overrides [[afterCopy]] if set.
     * - forceCopy: boolean, whether the directory being published should be copied even if
     *   it is found in the target directory. This option is used only when publishing a directory.
     *   This overrides [[forceCopy]] if set.
     *
     * @return string[] the path directory and the URL that the asset is published as.
     * @throws InvalidArgumentException if the asset to be published does not exist.
     */
    protected function publishDirectory($src, $options)
    {
        $dir = $this->hash($src);
        $dstDir = $this->basePath . DIRECTORY_SEPARATOR . $dir;
        if ($this->linkAssets) {
            if (!is_dir($dstDir)) {
                FileHelper::createDirectory(dirname($dstDir), $this->dirMode, true);
                try { // fix #6226 symlinking multi threaded
                    symlink($src, $dstDir);
                } catch (\Exception $e) {
                    if (!is_dir($dstDir)) {
                        throw $e;
                    }
                }
            }
        } elseif (!empty($options['forceCopy']) || ($this->forceCopy && !isset($options['forceCopy'])) || !is_dir($dstDir)) {
            $opts = array_merge(
                $options,
                [
                    'dirMode' => $this->dirMode,
                    'fileMode' => $this->fileMode,
                    'copyEmptyDirectories' => false,
                ]
            );
            if (!isset($opts['beforeCopy'])) {
                if ($this->beforeCopy !== null) {
                    $opts['beforeCopy'] = $this->beforeCopy;
                } else {
                    $opts['beforeCopy'] = function ($from, $to) {
                        return strncmp(basename($from), '.', 1) !== 0;
                    };
                }
            }
            if (!isset($opts['afterCopy']) && $this->afterCopy !== null) {
                $opts['afterCopy'] = $this->afterCopy;
            }
            FileHelper::copyDirectory($src, $dstDir, $opts);
        }

        return [$dstDir, $this->baseUrl . '/' . $dir];
    }

    /**
     * Returns the published path of a file path.
     * This method does not perform any publishing. It merely tells you
     * if the file or directory is published, where it will go.
     * @param string $path directory or file path being published
     * @return string|false string the published file path. False if the file or directory does not exist
     */
    public function getPublishedPath($path)
    {
        $path = Yii::getAlias($path);

        if (isset($this->_published[$path])) {
            return $this->_published[$path][0];
        }
        if (is_string($path) && ($path = realpath($path)) !== false) {
            return $this->basePath . DIRECTORY_SEPARATOR . $this->hash($path) . (is_file($path) ? DIRECTORY_SEPARATOR . basename($path) : '');
        }

        return false;
    }

    /**
     * Returns the URL of a published file path.
     * This method does not perform any publishing. It merely tells you
     * if the file path is published, what the URL will be to access it.
     * @param string $path directory or file path being published
     * @return string|false string the published URL for the file or directory. False if the file or directory does not exist.
     */
    public function getPublishedUrl($path)
    {
        $path = Yii::getAlias($path);

        if (isset($this->_published[$path])) {
            return $this->_published[$path][1];
        }
        if (is_string($path) && ($path = realpath($path)) !== false) {
            return $this->baseUrl . '/' . $this->hash($path) . (is_file($path) ? '/' . basename($path) : '');
        }

        return false;
    }

    /**
     * Generate a CRC32 hash for the directory path. Collisions are higher
     * than MD5 but generates a much smaller hash string.
     * @param string $path string to be hashed.
     * @return string hashed string.
     */
    protected function hash($path)
    {
        if (is_callable($this->hashCallback)) {
            return call_user_func($this->hashCallback, $path);
        }
        $path = (is_file($path) ? dirname($path) : $path) . filemtime($path);
        return sprintf('%x', crc32($path . Yii::getVersion() . '|' . $this->linkAssets));
    }
}
