<?php

namespace Luracast\Restler\Format;

use Exception;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Illuminate\View\View;
use Luracast\Restler\Data\Obj;
use Luracast\Restler\Defaults;
use Luracast\Restler\RestException;
use Luracast\Restler\Restler;
use Luracast\Restler\Scope;
use Luracast\Restler\UI\Nav;
use Luracast\Restler\Util;

/**
 * Html template format
 *
 * @category   Framework
 * @package    Restler
 * @subpackage format
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 * @version    4
 */
class HtmlFormat extends Format
{
    public static $mime = 'text/html';
    public static $extension = 'html';
    public static $view;
    public static $errorView = 'debug.php';
    public static $template = 'php';
    public static $handleSession = true;

    public static $useSmartViews = true;
    /**
     * @var null|string defaults to template named folder in Defaults::$cacheDirectory
     */
    public static $cacheDirectory = null;
    /**
     * @var array global key value pair to be supplied to the templates. All
     * keys added here will be available as a variable inside the template
     */
    public static $data = array();
    /**
     * @var string set it to the location of your the view files. Defaults to
     * views folder which is same level as vendor directory.
     */
    public static $viewPath;
    /**
     * @var array template and its custom extension key value pair
     */
    public static $customTemplateExtensions = array('blade' => 'blade.php');
    /**
     * @var bool used internally for error handling
     */
    protected static $parseViewMetadata = true;
    /**
     * @var Restler;
     */
    public $restler;

    public function __construct()
    {
        //============ SESSION MANAGEMENT =============//
        if (static::$handleSession) {
            if (session_start() && isset($_SESSION['flash'])) {
                static::$data['flash'] = $_SESSION['flash'];
                unset($_SESSION['flash']);
            }
        }
        if (!static::$viewPath) {
            $array = explode('vendor', __DIR__, 2);
            if (1 === count($array)) {
                $array = explode('src', __DIR__, 2);
            }
            static::$viewPath = $array[0] . 'views';
        }
    }

    public static function blade(array $data, $debug = true)
    {
        if (!class_exists('\Illuminate\View\View', true))
            throw new RestException(500,
                'Blade templates require laravel view classes to be installed using `composer install`');
        $resolver = new EngineResolver();
        $files = new Filesystem();
        $compiler = new BladeCompiler($files, static::$cacheDirectory);
        $engine = new CompilerEngine($compiler);
        $resolver->register('blade', function () use ($engine) {
            return $engine;
        });

        /** @var Restler $restler */
        $restler = Scope::get('Restler');

        //Lets expose shortcuts for our classes
        spl_autoload_register(function ($className) use ($restler) {
            if (isset($restler->apiMethodInfo->metadata['scope'][$className])) {
                return class_alias($restler->apiMethodInfo->metadata['scope'][$className], $className);
            }
            if (isset(Scope::$classAliases[$className])) {
                return class_alias(Scope::$classAliases[$className], $className);
            }
            return false;
        }, true, true);

        $viewFinder = new FileViewFinder($files, array(static::$viewPath));
        $factory = new Factory($resolver, $viewFinder, new Dispatcher());
        $path = $viewFinder->find(self::$view);
        $view = new View($factory, $engine, self::$view, $path, $data);
        $factory->callCreator($view);
        return $view->render();
    }

    public static function twig(array $data, $debug = true)
    {
        if (!class_exists('\Twig_Environment', true))
            throw new RestException(500,
                'Twig templates require twig classes to be installed using `composer install`');
        $loader = new \Twig_Loader_Filesystem(static::$viewPath);
        $twig = new \Twig_Environment($loader, array(
            'cache' => static::$cacheDirectory,
            'debug' => $debug,
            'use_strict_variables' => $debug,
        ));
        if ($debug)
            $twig->addExtension(new \Twig_Extension_Debug());

        $twig->addFunction(
            new \Twig_SimpleFunction(
                'form',
                'Luracast\Restler\UI\Forms::get',
                array('is_safe' => array('html'))
            )
        );
        $twig->addFunction(
            new \Twig_SimpleFunction(
                'form_key',
                'Luracast\Restler\UI\Forms::key'
            )
        );
        $twig->addFunction(
            new \Twig_SimpleFunction(
                'nav',
                'Luracast\Restler\UI\Nav::get'
            )
        );

        $twig->registerUndefinedFunctionCallback(function ($name) {
            if (
                isset(HtmlFormat::$data[$name]) &&
                is_callable(HtmlFormat::$data[$name])
            ) {
                return new \Twig_SimpleFunction(
                    $name,
                    HtmlFormat::$data[$name]
                );
            }
            return false;
        });

        $template = $twig->loadTemplate(static::getViewFile());
        return $template->render($data);
    }

    public static function getViewFile($fullPath = false, $includeExtension = true)
    {
        $v = $fullPath ? static::$viewPath . '/' : '';
        $v .= static::$view;
        if ($includeExtension)
            $v .= '.' . static::getViewExtension();
        return $v;
    }

    public static function getViewExtension()
    {
        return isset(static::$customTemplateExtensions[static::$template])
            ? static::$customTemplateExtensions[static::$template]
            : static::$template;
    }

    public static function handlebar(array $data, $debug = true)
    {
        return static::mustache($data, $debug);
    }

    public static function mustache(array $data, $debug = true)
    {
        if (!class_exists('\Mustache_Engine', true))
            throw new RestException(
                500,
                'Mustache/Handlebar templates require mustache classes ' .
                'to be installed using `composer install`'
            );
        if (!isset($data['nav']))
            $data['nav'] = array_values(Nav::get());
        $options = array(
            'loader' => new \Mustache_Loader_FilesystemLoader(
                static::$viewPath,
                array('extension' => static::getViewExtension())
            ),
            'helpers' => array(
                'form' => function ($text, \Mustache_LambdaHelper $m) {
                    $params = explode(',', $m->render($text));
                    return call_user_func_array(
                        'Luracast\Restler\UI\Forms::get',
                        $params
                    );
                },
            )
        );
        if (!$debug)
            $options['cache'] = static::$cacheDirectory;
        $m = new \Mustache_Engine($options);
        return $m->render(static::getViewFile(), $data);
    }

    public static function php(array $data, $debug = true)
    {
        if (static::$view == 'debug')
            static::$viewPath = dirname(__DIR__) . '/views';
        $view = static::getViewFile(true);

        if (!is_readable($view)) {
            throw new RestException(
                500,
                "view file `$view` is not readable. " .
                'Check for file presence and file permissions'
            );
        }

        $path = static::$viewPath . DIRECTORY_SEPARATOR;
        $template = function ($view) use ($data, $path) {
            $form = function () {
                return call_user_func_array(
                    'Luracast\Restler\UI\Forms::get',
                    func_get_args()
                );
            };
            if (!isset($data['form']))
                $data['form'] = $form;
            $nav = function () {
                return call_user_func_array(
                    'Luracast\Restler\UI\Nav::get',
                    func_get_args()
                );
            };
            if (!isset($data['nav']))
                $data['nav'] = $nav;

            $_ = function () use ($data, $path) {
                extract($data);
                $args = func_get_args();
                $task = array_shift($args);
                switch ($task) {
                    case 'require':
                    case 'include':
                        $file = $path . $args[0];
                        if (is_readable($file)) {
                            if (
                                isset($args[1]) &&
                                ($arrays = Util::nestedValue($data, $args[1]))
                            ) {
                                $str = '';
                                foreach ($arrays as $arr) {
                                    extract($arr);
                                    $str .= include $file;
                                }
                                return $str;
                            } else {
                                return include $file;
                            }
                        }
                        break;
                    case 'if':
                        if (count($args) < 2)
                            $args[1] = '';
                        if (count($args) < 3)
                            $args[2] = '';
                        return $args[0] ? $args[1] : $args[2];
                        break;
                    default:
                        if (isset($data[$task]) && is_callable($data[$task]))
                            return call_user_func_array($data[$task], $args);
                }
                return '';
            };
            extract($data);
            return @include $view;
        };
        $value = $template($view);
        if (is_string($value))
            return $value;
    }

    /**
     * Encode the given data in the format
     *
     * @param array $data resulting data that needs to
     *                                     be encoded in the given format
     * @param boolean $humanReadable set to TRUE when restler
     *                                     is not running in production mode.
     *                                     Formatter has to make the encoded
     *                                     output more human readable
     *
     * @return string encoded string
     * @throws \Exception
     */
    public function encode($data, $humanReadable = false)
    {
        if (!is_readable(static::$viewPath)) {
            throw new \Exception(
                'The views directory `'
                . self::$viewPath . '` should exist with read permission.'
            );
        }
        static::$data['basePath'] = dirname($_SERVER['SCRIPT_NAME']);
        static::$data['baseUrl'] = $this->restler->getBaseUrl();
        static::$data['currentPath'] = $this->restler->url;

        try {
            $exception = $this->restler->exception;
            $success = is_null($exception);
            $error = $success ? null : $exception->getMessage();
            $data = array(
                'response' => Obj::toArray($data),
                'stages' => $this->restler->getEvents(),
                'success' => $success,
                'error' => $error
            );
            $info = $data['api'] = $this->restler->apiMethodInfo;
            $metadata = Util::nestedValue(
                $this->restler, 'apiMethodInfo', 'metadata'
            );
            $view = $success ? 'view' : 'errorView';
            $value = false;
            if (static::$parseViewMetadata && isset($metadata[$view])) {
                if (is_array($metadata[$view])) {
                    self::$view = $metadata[$view]['description'];
                    $value = Util::nestedValue(
                        $metadata[$view], 'properties', 'value'
                    );
                } else {
                    self::$view = $metadata[$view];
                }
            } elseif (!self::$view) {
                $file = static::$viewPath . '/' . $this->restler->url . '.' . static::getViewExtension();
                self::$view = static::$useSmartViews && is_readable($file)
                    ? $this->restler->url
                    : static::$errorView;
            }
            if (
                isset($metadata['param'])
                && (!$value || 0 === strpos($value, 'request'))
            ) {
                $params = $metadata['param'];
                foreach ($params as $index => &$param) {
                    $index = intval($index);
                    if (is_numeric($index)) {
                        $param['value'] = $this
                            ->restler
                            ->apiMethodInfo
                            ->parameters[$index];
                    }
                }
                $data['request']['parameters'] = $params;
            }
            if ($value) {
                $data = Util::nestedValue($data, explode('.', $value));
            }
            $data += static::$data;
            if (false === ($i = strrpos(self::$view, '.'))) {
                $template = self::$template;
            } else {
                self::$template = $template = substr(self::$view, $i + 1);
                self::$view = substr(self::$view, 0, $i);
            }
            if (!static::$cacheDirectory) {
                static::$cacheDirectory = Defaults::$cacheDirectory . DIRECTORY_SEPARATOR . $template;
                if (!file_exists(static::$cacheDirectory)) {
                    if (!mkdir(static::$cacheDirectory)) {
                        throw new RestException(500, 'Unable to create cache directory `' . static::$cacheDirectory . '`');
                    }
                }
            }
            if (method_exists($class = get_called_class(), $template)) {
                return call_user_func("$class::$template", $data, $humanReadable);
            }
            throw new RestException(500, "Unsupported template system `$template`");
        } catch (Exception $e) {
            static::$parseViewMetadata = false;
            $this->reset();
            throw $e;
        }
    }

    private function reset()
    {
        static::$mime = 'text/html';
        static::$extension = 'html';
        static::$view = 'debug';
        static::$template = 'php';
    }

    /**
     * Decode the given data from the format
     *
     * @param string $data
     *            data sent from client to
     *            the api in the given format.
     *
     * @return array associative array of the parsed data
     *
     * @throws RestException
     */
    public function decode($data)
    {
        throw new RestException(500, 'HtmlFormat is write only');
    }

    /**
     * @return bool false as HTML format is write only
     */
    public function isReadable()
    {
        return false;
    }

    /**
     * Get MIME type => Extension mappings as an associative array
     *
     * @return array list of mime strings for the format
     * @example array('application/json'=>'json');
     */
    public function getMIMEMap()
    {
        return array(
            static::$mime => static::$extension
        );
    }

    /**
     * Set the selected MIME type
     *
     * @param string $mime MIME type
     */
    public function setMIME($mime)
    {
        static::$mime = $mime;
    }

    /**
     * Get selected MIME type
     */
    public function getMIME()
    {
        return static::$mime;
    }

    /**
     * Get the selected file extension
     *
     * @return string file extension
     */
    public function getExtension()
    {
        return static::$extension;
    }

    /**
     * Set the selected file extension
     *
     * @param string $extension file extension
     */
    public function setExtension($extension)
    {
        static::$extension = $extension;
    }
}
