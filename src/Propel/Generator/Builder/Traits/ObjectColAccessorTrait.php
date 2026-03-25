<?php

namespace Propel\Generator\Builder\Traits;

use DateTime;
use Exception;
use Propel\Common\Util\SetColumnConverter;
use Propel\Generator\Exception\EngineException;
use Propel\Generator\Model\Column;
use Propel\Generator\Platform\MysqlPlatform;

use function in_array;
use function sprintf;

trait ObjectColAccessorTrait
{
    use  ObjectBuilderTrait;


}
