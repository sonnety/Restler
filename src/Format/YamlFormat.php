<?php
namespace Luracast\Restler\Format;

use Symfony\Component\Yaml\Yaml;
use Luracast\Restler\Data\Obj;

/**
 * YAML Format for Restler Framework
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
class YamlFormat extends Format
{
    const MIME = 'text/plain';
    const EXTENSION = 'yaml';

    public function encode($data, $humanReadable = false)
    {
//      require_once 'sfyaml.php';
        return @Yaml::dump(Obj::toArray($data));
    }

    public function decode($data)
    {
//      require_once 'sfyaml.php';
        return Yaml::parse($data);
    }
}

