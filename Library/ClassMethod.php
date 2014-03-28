<?php

/*
 +----------------------------------------------------------------------+
 | Zephir Language                                                      |
 +----------------------------------------------------------------------+
 | Copyright (c) 2013-2014 Zephir Team                                  |
 +----------------------------------------------------------------------+
 | This source file is subject to version 1.0 of the MIT license,       |
 | that is bundled with this package in the file LICENSE, and is        |
 | available through the world-wide-web at the following url:           |
 | http://www.zephir-lang.com/license                                   |
 |                                                                      |
 | If you did not receive a copy of the MIT license and are unable      |
 | to obtain it through the world-wide-web, please send a note to       |
 | license@zephir-lang.com so we can mail you a copy immediately.       |
 +----------------------------------------------------------------------+
*/

namespace Zephir;

use Zephir\Passes\LocalContextPass;
use Zephir\Passes\StaticTypeInference;
use Zephir\Passes\CallGathererPass;
use Zephir\Builder\VariableBuilder;
use Zephir\Builder\LiteralBuilder;
use Zephir\Builder\ParameterBuilder;
use Zephir\Builder\StatementsBlockBuilder;
use Zephir\Builder\Operators\UnaryOperatorBuilder;
use Zephir\Builder\Operators\BinaryOperatorBuilder;
use Zephir\Builder\Operators\TypeOfOperatorBuilder;
use Zephir\Builder\Operators\NewInstanceOperatorBuilder;
use Zephir\Builder\Statements\IfStatementBuilder;
use Zephir\Builder\Statements\ThrowStatementBuilder;
use Zephir\Statements\IfStatement;
use Zephir\Detectors\WriteDetector;

/**
 * ClassMethod
 *
 * Represents a class method
 */
class ClassMethod
{
    /**
     * @var ClassDefinition
     */
    protected $_classDefinition;

    /**
     * @var array
     */
    protected $_visibility;

    protected $_name;

    /**
     * @var ClassMethodParameters
     */
    protected $_parameters;

    protected $_statements;

    protected $_docblock;

    protected $_returnTypes;

    protected $_returnClassTypes;

    protected $_void = false;

    /**
     * @var array|null
     */
    protected $_expression;

    /**
     * ClassMethod constructor
     *
     * @param ClassDefinition $classDefinition
     * @param array $visibility
     * @param $name
     * @param $parameters
     * @param StatementsBlock $statements
     * @param null $docblock
     * @param null $returnType
     * @param array $original
     */
    public function __construct(ClassDefinition $classDefinition, array $visibility, $name, $parameters, StatementsBlock $statements = null, $docblock = null, $returnType = null, array $original = null)
    {
        $this->checkVisibility($visibility, $name, $original);

        $this->_classDefinition = $classDefinition;
        $this->_visibility = $visibility;
        $this->_name = $name;
        $this->_parameters = $parameters;
        $this->_statements = $statements;
        $this->_docblock = $docblock;
        $this->_expression = $original;

        if ($returnType['void']) {
            $this->_void = true;
            return;
        }

        if (isset($returnType['list'])) {
            $types = array();
            $castTypes = array();
            foreach ($returnType['list'] as $returnTypeItem) {
                if (isset($returnTypeItem['cast'])) {
                    if (isset($returnTypeItem['cast']['collection'])) {
                        continue;
                    }
                    $castTypes[$returnTypeItem['cast']['value']] = $returnTypeItem['cast']['value'];
                } else {
                    $types[$returnTypeItem['data-type']] = $returnTypeItem;
                }
            }
            if (count($castTypes)) {
                $types['object'] = array();
                $this->_returnClassTypes = $castTypes;
            }
            if (count($types)) {
                $this->_returnTypes = $types;
            }
        }
    }

    /**
     * Getter for statements block
     *
     * @return StatementsBlock $statements Statements block
     */
    public function getStatementsBlock()
    {
        return $this->_statements;
    }

    /**
     * Setter for statements block
     *
     * @param StatementsBlock $statementsBlock
     */
    public function setStatementsBlock(StatementsBlock $statementsBlock)
    {
        $this->_statements = $statementsBlock;
    }

    /**
     * Checks for visibility congruence
     *
     * @param array $visibility
     * @param string $name
     * @param array $original
     * @throws CompilerException
     */
    public function checkVisibility(array $visibility, $name, array $original = null)
    {
        if (count($visibility) > 1) {
            if (in_array('public', $visibility) && in_array('protected', $visibility)) {
                throw new CompilerException("Method '$name' cannot be 'public' and 'protected' at the same time", $original);
            }

            if (in_array('public', $visibility) && in_array('private', $visibility)) {
                throw new CompilerException("Method '$name' cannot be 'public' and 'private' at the same time", $original);
            }

            if (in_array('private', $visibility) && in_array('protected', $visibility)) {
                throw new CompilerException("Method '$name' cannot be 'protected' and 'private' at the same time", $original);
            }
        }

        if ($name == '__construct') {
            if (in_array('static', $visibility)) {
                throw new CompilerException("Constructors cannot be 'static'", $original);
            }
        } else {
            if ($name == '__destruct') {
                if (in_array('static', $visibility)) {
                    throw new CompilerException("Destructors cannot be 'static'", $original);
                }
            }
        }

        if (is_array($visibility)) {
            $this->isStatic = in_array('static', $visibility);
            $this->isFinal = in_array('final', $visibility);
            $this->isPublic = in_array('public', $visibility);
        }
    }

    public function setIsStatic($static)
    {
        $this->isStatic = $static;
    }

    /**
     * Returns the class definition where the method was declared
     *
     * @return ClassDefinition
     */
    public function getClassDefinition()
    {
        return $this->_classDefinition;
    }

    /**
     * Returns the method name
     *
     * @return string
     */
    public function getName()
    {
        return $this->_name;
    }

    /**
     * Returns the docblock
     *
     * @return string
     */
    public function getDocBlock()
    {
        return $this->_docblock;
    }

    /**
     * Returns the parameters
     *
     * @return ClassMethodParameters
     */
    public function getParameters()
    {
        return $this->_parameters;
    }

    /**
     * Checks if the method has return-type or cast hints
     *
     * @return boolean
     */
    public function hasReturnTypes()
    {
        if (count($this->_returnTypes)) {
            return true;
        }

        if (count($this->_returnClassTypes)) {
            return true;
        }

        return false;
    }

    /**
     * Checks whether at least one return type hint is null compatible
     *
     * @param string $type
     */
    public function areReturnTypesNullCompatible($type = null)
    {
        return false;
    }

    /**
     * Checks whether at least one return type hint is integer compatible
     *
     * @param string $type
     */
    public function areReturnTypesIntCompatible($type = null)
    {
        if (count($this->_returnTypes)) {
            foreach ($this->_returnTypes as $returnType => $definition) {
                switch ($returnType) {
                    case 'int':
                    case 'uint':
                    case 'char':
                    case 'uchar':
                    case 'long':
                    case 'ulong':
                        return true;
                }
            }
        }
        return false;
    }

    /**
     * Checks whether at least one return type hint is double compatible
     *
     * @param string $type
     */
    public function areReturnTypesDoubleCompatible($type = null)
    {
        if (count($this->_returnTypes)) {
            foreach ($this->_returnTypes as $returnType => $definition) {
                switch ($returnType) {
                    case 'double':
                        return true;
                }
            }
        }
        return false;
    }

    /**
     * Checks whether at least one return type hint is integer compatible
     *
     * @param string $type
     */
    public function areReturnTypesBoolCompatible($type = null)
    {
        if (count($this->_returnTypes)) {
            foreach ($this->_returnTypes as $returnType => $definition) {
                switch ($returnType) {
                    case 'bool':
                        return true;
                }
            }
        }
        return false;
    }

    /**
     * Checks whether at least one return type hint is integer compatible
     *
     * @param string $type
     */
    public function areReturnTypesStringCompatible($type = null)
    {
        if (count($this->_returnTypes)) {
            foreach ($this->_returnTypes as $returnType => $definition) {
                switch ($returnType) {
                    case 'string':
                        return true;
                }
            }
        }
        return false;
    }

    /**
     * Returned type hints by the method
     *
     * @return array
     */
    public function getReturnTypes()
    {
        return $this->_returnTypes;
    }

    /**
     * Returned class-type hints by the method
     *
     * @return array
     */
    public function getReturnClassTypes()
    {
        return $this->_returnClassTypes;
    }

    /**
     * Returns the number of parameters the method has
     *
     * @return boolean
     */
    public function hasParameters()
    {
        if (is_object($this->_parameters)) {
            return count($this->_parameters->getParameters()) > 0;
        }
        return false;
    }

    /**
     * Returns the number of parameters the method has
     *
     * @return int
     */
    public function getNumberOfParameters()
    {
        if (is_object($this->_parameters)) {
            return count($this->_parameters->getParameters());
        }
        return 0;
    }

    /**
     * Returns the number of required parameters the method has
     *
     * @return int
     */
    public function getNumberOfRequiredParameters()
    {
        if (is_object($this->_parameters)) {
            $parameters = $this->_parameters->getParameters();
            if (count($parameters)) {
                $required = 0;
                foreach ($parameters as $parameter) {
                    if (!isset($parameter['default'])) {
                        $required++;
                    }
                }
                return $required;
            }
        }
        return 0;
    }

    /**
     * Returns the number of required parameters the method has
     *
     * @return string
     */
    public function getInternalParameters()
    {
        if (is_object($this->_parameters)) {
            $parameters = $this->_parameters->getParameters();
            if (count($parameters)) {
                return count($parameters) . ', ...';
            }
        }
        return "";
    }

    /**
     * Checks whether the method has a specific modifier
     *
     * @param string $modifier
     * @return boolean
     */
    public function hasModifier($modifier)
    {
        foreach ($this->_visibility as $visibility) {
            if ($visibility == $modifier) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns method visibility modifiers
     *
     * @return array
     */
    public function getVisibility()
    {
        return $this->_visibility;
    }

    /**
     * Returns the C-modifier flags
     *
     * @return string
     */
    public function getModifiers()
    {
        $modifiers = array();
        foreach ($this->_visibility as $visibility) {
            switch ($visibility) {
                case 'public':
                    $modifiers['ZEND_ACC_PUBLIC'] = $visibility;
                    break;
                case 'protected':
                    $modifiers['ZEND_ACC_PROTECTED'] = $visibility;
                    break;
                case 'private':
                    $modifiers['ZEND_ACC_PRIVATE'] = $visibility;
                    break;
                case 'static':
                    $modifiers['ZEND_ACC_STATIC'] = $visibility;
                    break;
                case 'final':
                    $modifiers['ZEND_ACC_FINAL'] = $visibility;
                    break;
                case 'inline':
                    break;
                case 'scoped':
                    break;
                default:
                    throw new Exception('Unknown modifier "' . $visibility . '"');
            }
        }
        if ($this->_name == '__construct') {
            $modifiers['ZEND_ACC_CTOR'] = true;
        } else {
            if ($this->_name == '__destruct') {
                $modifiers['ZEND_ACC_DTOR'] = true;
            }
        }
        return join('|', array_keys($modifiers));
    }

    /**
     * Checks if the method must not return any value
     *
     * @return boolean
     */
    public function isVoid()
    {
        return $this->_void;
    }

    /**
     * Checks if the method is inline
     *
     * @return boolean
     */
    public function isInline()
    {
        if (is_array($this->_visibility)) {
            return in_array('inline', $this->_visibility);
        }
        return false;
    }

    /**
     * Checks if the method is private
     *
     * @return boolean
     */
    public function isPrivate()
    {
        if (is_array($this->_visibility)) {
            return in_array('private', $this->_visibility);
        }
        return false;
    }

    /**
     * Checks if the method is protected
     *
     * @return boolean
     */
    public function isProtected()
    {
        if (is_array($this->_visibility)) {
            return in_array('protected', $this->_visibility);
        }
        return false;
    }

    protected $isPublic;

     /**
     * Checks if the method is public
     *
     * @return boolean
     */
    public function isPublic()
    {
        return $this->isPublic;
    }

    protected $isStatic = false;

    /**
     * Checks if the method is static
     *
     * @return boolean
     */
    public function isStatic()
    {
        return $this->isStatic;
    }

    protected $isFinal = false;

    /**
     * Checks if the method is final
     *
     * @return boolean
     */
    public function isFinal()
    {
        return $this->isFinal;
    }

    /**
     * Check if the current method is a constructor
     *
     * @return boolean
     */
    public function isConstructor()
    {
        return $this->_name == '__construct';
    }

    /**
     * Replace macros
     *
     * @param SymbolTable $symbolTable
     * @param string $containerCode
     */
    public function removeMemoryStackReferences(SymbolTable $symbolTable, $containerCode)
    {
        if (!$symbolTable->getMustGrownStack()) {
            $containerCode = str_replace('ZEPHIR_THROW_EXCEPTION_STR', 'ZEPHIR_THROW_EXCEPTION_STRW', $containerCode);
            $containerCode = str_replace('ZEPHIR_THROW_EXCEPTION_ZVAL', 'ZEPHIR_THROW_EXCEPTION_ZVALW', $containerCode);
            $containerCode = str_replace('RETURN_THIS', 'RETURN_THISW', $containerCode);
            $containerCode = str_replace('RETURN_LCTOR', 'RETURN_LCTORW', $containerCode);
            $containerCode = str_replace('RETURN_CTOR', 'RETURN_CTORW', $containerCode);
            $containerCode = str_replace('RETURN_NCTOR', 'RETURN_NCTORW', $containerCode);
            $containerCode = str_replace('RETURN_CCTOR', 'RETURN_CCTORW', $containerCode);
            $containerCode = str_replace('RETURN_MM_NULL', 'RETURN_NULL', $containerCode);
            $containerCode = str_replace('RETURN_MM_BOOL', 'RETURN_BOOL', $containerCode);
            $containerCode = str_replace('RETURN_MM_FALSE', 'RETURN_FALSE', $containerCode);
            $containerCode = str_replace('RETURN_MM_TRUE', 'RETURN_TRUE', $containerCode);
            $containerCode = str_replace('RETURN_MM_STRING', 'RETURN_STRING', $containerCode);
            $containerCode = str_replace('RETURN_MM_LONG', 'RETURN_LONG', $containerCode);
            $containerCode = str_replace('RETURN_MM_DOUBLE', 'RETURN_DOUBLE', $containerCode);
            $containerCode = str_replace('RETURN_MM_FALSE', 'RETURN_FALSE', $containerCode);
            $containerCode = str_replace('RETURN_MM_EMPTY_STRING', 'RETURN_MM_EMPTY_STRING', $containerCode);
            $containerCode = str_replace('RETURN_MM_EMPTY_ARRAY', 'RETURN_EMPTY_ARRAY', $containerCode);
            $containerCode = str_replace('RETURN_MM_MEMBER', 'RETURN_MEMBER', $containerCode);
            $containerCode = str_replace('RETURN_MM()', 'return', $containerCode);
            $containerCode = preg_replace('/[ \t]+ZEPHIR_MM_RESTORE\(\);' . PHP_EOL . '/s', '', $containerCode);
        }
        return $containerCode;
    }

    /**
     * Assigns a default value
     *
     * @param array $parameter
     * @param CompilationContext $compilationContext
     * @return string
     * @throws CompilerException
     */
    public function assignDefaultValue(array $parameter, CompilationContext $compilationContext)
    {
        if (isset($parameter['data-type'])) {
            $dataType = $parameter['data-type'];
        } else {
            $dataType = 'variable';
        }

        /**
         * Class-Hinted parameters only can be null?
         */
        if (isset($parameter['cast'])) {
            if ($parameter['default']['type'] != 'null') {
                throw new CompilerException('Class-Hinted parameters only can have "null" as default parameter', $parameter);
            }
        }

        $code = '';
        switch ($dataType) {

            case 'int':
            case 'uint':
            case 'long':
            case 'ulong':
                switch ($parameter['default']['type']) {
                    case 'null':
                        $code .= "\t\t" . $parameter['name'] . ' = 0;' . PHP_EOL;
                        break;
                    case 'int':
                    case 'uint':
                    case 'long':
                        $code .= "\t\t" . $parameter['name'] . ' = ' . $parameter['default']['value'] . ';' . PHP_EOL;
                        break;
                    case 'double':
                        $code .= "\t\t" . $parameter['name'] . ' = (int) ' . $parameter['default']['value'] . ';' . PHP_EOL;
                        break;
                    default:
                        throw new CompilerException("Default parameter value type: " . $parameter['default']['type'] . " cannot be assigned to variable(int)", $parameter);
                }
                break;

            case 'double':
                switch ($parameter['default']['type']) {
                    case 'null':
                        $code .= "\t\t" . $parameter['name'] . ' = 0;' . PHP_EOL;
                        break;
                    case 'int':
                    case 'uint':
                    case 'long':
                        $code .= "\t\t" . $parameter['name'] . ' = (double) ' . $parameter['default']['value'] . ';' . PHP_EOL;
                        break;
                    case 'double':
                        $code .= "\t\t" . $parameter['name'] . ' = ' . $parameter['default']['value'] . ';' . PHP_EOL;
                        break;
                    default:
                        throw new CompilerException("Default parameter value type: " . $parameter['default']['type'] . " cannot be assigned to variable(double)", $parameter);
                }
                break;

            case 'bool':
                switch ($parameter['default']['type']) {
                    case 'null':
                        $code .= "\t\t" . $parameter['name'] . ' = 0;' . PHP_EOL;
                        break;
                    case 'bool':
                        if ($parameter['default']['value'] == 'true') {
                            $code .= "\t\t" . $parameter['name'] . ' = 1;' . PHP_EOL;
                        } else {
                            $code .= "\t\t" . $parameter['name'] . ' = 0;' . PHP_EOL;
                        }
                        break;
                    default:
                        throw new CompilerException("Default parameter value type: " . $parameter['default']['type'] . " cannot be assigned to variable(bool)", $parameter);
                }
                break;

            case 'string':
                $compilationContext->symbolTable->mustGrownStack(true);
                $compilationContext->headersManager->add('kernel/memory');
                switch ($parameter['default']['type']) {
                    case 'null':
                        $code .= "\t\t" . 'ZEPHIR_INIT_VAR(' . $parameter['name'] . ');' . PHP_EOL;
                        $code .= "\t\t" . 'ZVAL_EMPTY_STRING(' . $parameter['name'] . ');' . PHP_EOL;
                        break;
                    case 'string':
                        $code .= "\t\t" . 'ZEPHIR_INIT_VAR(' . $parameter['name'] . ');' . PHP_EOL;
                        $code .= "\t\t" . 'ZVAL_STRING(' . $parameter['name'] . ', "' . $parameter['default']['value'] . '", 1);' . PHP_EOL;
                        break;
                    default:
                        throw new CompilerException("Default parameter value type: " . $parameter['default']['type'] . " cannot be assigned to variable(string)", $parameter);
                }
                break;

            case 'array':
                $compilationContext->symbolTable->mustGrownStack(true);
                $compilationContext->headersManager->add('kernel/memory');
                switch ($parameter['default']['type']) {
                    case 'null':
                    case 'empty-array':
                    case 'array':
                        $code .= "\t\t" . 'ZEPHIR_INIT_VAR(' . $parameter['name'] . ');' . PHP_EOL;
                        $code .= "\t\t" . 'array_init(' . $parameter['name'] . ');' . PHP_EOL;
                        break;
                    default:
                        throw new CompilerException("Default parameter value type: " . $parameter['default']['type'] . " cannot be assigned to variable(array)", $parameter);
                }
                break;

            case 'variable':
                switch ($parameter['default']['type']) {

                    case 'int':
                    case 'uint':
                    case 'long':
                    case 'ulong':
                        $compilationContext->symbolTable->mustGrownStack(true);
                        $compilationContext->headersManager->add('kernel/memory');
                        $code .= "\t\t" . 'ZEPHIR_INIT_VAR(' . $parameter['name'] . ');' . PHP_EOL;
                        $code .= "\t\t" . 'ZVAL_LONG(' . $parameter['name'] . ', ' . $parameter['default']['value'] . ');' . PHP_EOL;
                        break;

                    case 'double':
                        $compilationContext->symbolTable->mustGrownStack(true);
                        $compilationContext->headersManager->add('kernel/memory');
                        $code .= "\t\t" . 'ZEPHIR_INIT_VAR(' . $parameter['name'] . ');' . PHP_EOL;
                        $code .= "\t\t" . 'ZVAL_DOUBLE(' . $parameter['name'] . ', ' . $parameter['default']['value'] . ');' . PHP_EOL;
                        break;

                    case 'string':
                        $compilationContext->symbolTable->mustGrownStack(true);
                        $compilationContext->headersManager->add('kernel/memory');
                        $code .= "\t\t" . 'ZEPHIR_INIT_VAR(' . $parameter['name'] . ');' . PHP_EOL;
                        $code .= "\t\t" . 'ZVAL_STRING(' . $parameter['name'] . ', "' . Utils::addSlashes($parameter['default']['value']) . '", 1);' . PHP_EOL;
                        break;

                    case 'bool':
                        $expectedMutations = $compilationContext->symbolTable->getExpectedMutations($parameter['name']);
                        if ($expectedMutations < 2) {
                            if ($parameter['default']['value'] == 'true') {
                                $code .= "\t\t" . $parameter['name'] . ' = ZEPHIR_GLOBAL(global_true);' . PHP_EOL;
                            } else {
                                $code .= "\t\t" . $parameter['name'] . ' = ZEPHIR_GLOBAL(global_false);' . PHP_EOL;
                            }
                        } else {
                            $compilationContext->symbolTable->mustGrownStack(true);
                            $compilationContext->headersManager->add('kernel/memory');
                            if ($parameter['default']['value'] == 'true') {
                                $code .= "\t\t" . 'ZEPHIR_CPY_WRT(' . $parameter['name'] . ', ZEPHIR_GLOBAL(global_true));' . PHP_EOL;
                            } else {
                                $code .= "\t\t" . 'ZEPHIR_CPY_WRT(' . $parameter['name'] . ', ZEPHIR_GLOBAL(global_false));' . PHP_EOL;
                            }
                        }
                        break;

                    case 'null':
                        $expectedMutations = $compilationContext->symbolTable->getExpectedMutations($parameter['name']);
                        if ($expectedMutations < 2) {
                            $code .= "\t\t" . $parameter['name'] . ' = ZEPHIR_GLOBAL(global_null);' . PHP_EOL;
                        } else {
                            $compilationContext->symbolTable->mustGrownStack(true);
                            $compilationContext->headersManager->add('kernel/memory');
                            $code .= "\t\t" . 'ZEPHIR_CPY_WRT(' . $parameter['name'] . ', ZEPHIR_GLOBAL(global_null));' . PHP_EOL;
                        }
                        break;

                    case 'empty-array':
                        $compilationContext->symbolTable->mustGrownStack(true);
                        $compilationContext->headersManager->add('kernel/memory');
                        $code .= "\t\t" . 'ZEPHIR_INIT_VAR(' . $parameter['name'] . ');' . PHP_EOL;
                        $code .= "\t\t" . 'array_init(' . $parameter['name'] . ');' . PHP_EOL;
                        break;

                    default:
                        throw new CompilerException("Default parameter value type: " . $parameter['default']['type'] . " cannot be assigned to variable(variable)", $parameter);
                }
                break;

            default:
                throw new CompilerException("Default parameter type: " . $dataType, $parameter);
        }

        return $code;
    }

    /**
     * Assigns a zval value to a static low-level type
     *
     * @todo rewrite this to build ifs and throw from builders
     *
     * @param array $parameter
     * @param CompilationContext $compilationContext
     * @return string
     * @throws CompilerException
     */
    public function checkStrictType(array $parameter, CompilationContext $compilationContext)
    {
        if (isset($parameter['data-type'])) {
            $dataType = $parameter['data-type'];
        } else {
            $dataType = 'variable';
        }

        $compilationContext->headersManager->add('ext/spl/spl_exceptions');
        $compilationContext->headersManager->add('kernel/exception');

        switch ($dataType) {

            case 'int':
            case 'uint':
            case 'long':
                $code  = "\tif (Z_TYPE_P(" . $parameter['name'] . '_param) != IS_LONG) {' . PHP_EOL;
                $code .= "\t\t\t" . 'zephir_throw_exception_string(spl_ce_InvalidArgumentException, SL("Parameter \'' . $parameter['name'] . '\' must be a long/integer") TSRMLS_CC);' . PHP_EOL;
                $code .= "\t\t\t" . 'RETURN_MM_NULL();' . PHP_EOL;
                $code .= "\t\t" . '}' . PHP_EOL;
                $code .= PHP_EOL;
                $code .= "\t\t" . $parameter['name'] . ' = Z_LVAL_P(' . $parameter['name'] . '_param);' . PHP_EOL;
                return $code;

            case 'bool':
                $code  = "\tif (Z_TYPE_P(" . $parameter['name'] . '_param) != IS_BOOL) {' . PHP_EOL;
                $code .= "\t\t\t" . 'zephir_throw_exception_string(spl_ce_InvalidArgumentException, SL("Parameter \'' . $parameter['name'] . '\' must be a bool") TSRMLS_CC);' . PHP_EOL;
                $code .= "\t\t\t" . 'RETURN_MM_NULL();' . PHP_EOL;
                $code .= "\t\t" . '}' . PHP_EOL;
                $code .= PHP_EOL;
                $code .= "\t\t" . $parameter['name'] . ' = Z_BVAL_P(' . $parameter['name'] . '_param);' . PHP_EOL;
                return $code;

            case 'double':
                $code  = "\tif (Z_TYPE_P(" . $parameter['name'] . '_param) != IS_DOUBLE) {' . PHP_EOL;
                $code .= "\t\t" . 'zephir_throw_exception_string(spl_ce_InvalidArgumentException, SL("Parameter \'' . $parameter['name'] . '\' must be a double") TSRMLS_CC);' . PHP_EOL;
                $code .= "\t\t" . 'RETURN_MM_NULL();' . PHP_EOL;
                $code .= "\t" . '}' . PHP_EOL;
                $code .= PHP_EOL;
                $code .= "\t\t" . $parameter['name'] . ' = Z_DVAL_P(' . $parameter['name'] . '_param);' . PHP_EOL;
                return $code;

            case 'string':
            case 'ulong':
                $compilationContext->symbolTable->mustGrownStack(true);
                $code  = "\tif (Z_TYPE_P(" . $parameter['name'] . '_param) != IS_STRING && Z_TYPE_P(' . $parameter['name'] . '_param) != IS_NULL) {' . PHP_EOL;
                $code .= "\t\t" . 'zephir_throw_exception_string(spl_ce_InvalidArgumentException, SL("Parameter \'' . $parameter['name'] . '\' must be a string") TSRMLS_CC);' . PHP_EOL;
                $code .= "\t\t" . 'RETURN_MM_NULL();' . PHP_EOL;
                $code .= "\t" . '}' . PHP_EOL;
                $code .= PHP_EOL;
                $code .= "\tif (Z_TYPE_P(" . $parameter['name'] . '_param) == IS_STRING) {' . PHP_EOL;
                $code .= "\t\t" . $parameter['name'] . ' = ' . $parameter['name'] . '_param;' . PHP_EOL;
                $code .= "\t" . '} else {' . PHP_EOL;
                $code .= "\t\tZEPHIR_INIT_VAR(" . $parameter['name'] . ');' . PHP_EOL;
                $code .= "\t\tZVAL_EMPTY_STRING(" . $parameter['name'] . ');' . PHP_EOL;
                $code .= "\t" . '}' . PHP_EOL;
                return $code;

            case 'array':
            case 'object':
            case 'resource':
                $code  = "\tif (Z_TYPE_P(" . $parameter['name'] . ') != IS_'.strtoupper($dataType).') {' . PHP_EOL;
                $code .= "\t\t" . 'zephir_throw_exception_string(spl_ce_InvalidArgumentException, SL("Parameter \'' . $parameter['name'] . '\' must be an '.$dataType.'") TSRMLS_CC);' . PHP_EOL;
                $code .= "\t\t" . 'RETURN_MM_NULL();' . PHP_EOL;
                $code .= "\t" . '}' . PHP_EOL;
                $code .= PHP_EOL;
                return $code;

            case 'callable':
                $code  = "\tif (zephir_is_callable(" . $parameter['name'] . ' TSRMLS_CC) != 1) {' . PHP_EOL;
                $code .= "\t\t" . 'zephir_throw_exception_string(spl_ce_InvalidArgumentException, SL("Parameter \'' . $parameter['name'] . '\' must be callable") TSRMLS_CC);' . PHP_EOL;
                $code .= "\t\t" . 'RETURN_MM_NULL();' . PHP_EOL;
                $code .= "\t" . '}' . PHP_EOL;
                $code .= PHP_EOL;
                return $code;

            default:
                throw new CompilerException("Parameter type: " . $dataType, $parameter);
        }
    }

    /**
     * Assigns a zval value to a static low-level type
     *
     * @param array $parameter
     * @param CompilationContext $compilationContext
     * @return string
     * @throws CompilerException
     */
    public function assignZvalValue(array $parameter, CompilationContext $compilationContext)
    {
        if (isset($parameter['data-type'])) {
            $dataType = $parameter['data-type'];
        } else {
            $dataType = 'variable';
        }

        $compilationContext->headersManager->add('kernel/operators');
        switch ($dataType) {

            case 'int':
            case 'uint':
            case 'long':
            case 'ulong':
                return "\t" . $parameter['name'] . ' = zephir_get_intval(' . $parameter['name'] . '_param);' . PHP_EOL;

            case 'bool':
                return "\t" . $parameter['name'] . ' = zephir_get_boolval(' . $parameter['name'] . '_param);' . PHP_EOL;

            case 'double':
                return "\t" . $parameter['name'] . ' = zephir_get_doubleval(' . $parameter['name'] . '_param);' . PHP_EOL;

            case 'string':
                $compilationContext->symbolTable->mustGrownStack(true);
                return "\t" . 'zephir_get_strval(' . $parameter['name'] . ', ' . $parameter['name'] . '_param);' . PHP_EOL;

            case 'array':
                $compilationContext->symbolTable->mustGrownStack(true);
                return "\t" . 'zephir_get_arrval(' . $parameter['name'] . ', ' . $parameter['name'] . '_param);' . PHP_EOL;

            case 'variable':
            case 'callable':
            case 'object':
            case 'resource':
                break;

            default:
                throw new CompilerException("Parameter type: " . $dataType, $parameter);

        }
    }

    /**
     * Compiles the method
     *
     * @param CompilationContext $compilationContext
     * @return null
     * @throws CompilerException
     */
    public function compile(CompilationContext $compilationContext)
    {
        /**
         * Set the method currently being compiled
         */
        $compilationContext->currentMethod = $this;

        if (is_object($this->_statements)) {

            /**
             * This pass checks for zval variables than can be potentially
             * used without allocating memory and track it
             * these variables are stored in the stack
             */
            if ($compilationContext->config->get('local-context-pass', 'optimizations')) {
                $localContext = new LocalContextPass();
                $localContext->pass($this->_statements);
            } else {
                $localContext = null;
            }

            /**
             * This pass tries to infer types for dynamic variables
             * replacing them by low level variables
             */
            if ($compilationContext->config->get('static-type-inference', 'optimizations')) {
                $typeInference = new StaticTypeInference();
                $typeInference->pass($this->_statements);
                if ($compilationContext->config->get('static-type-inference-second-pass', 'optimizations')) {
                    $typeInference->reduce();
                    $typeInference->pass($this->_statements);
                }
            } else {
                $typeInference = null;
            }

            /**
             * This pass counts how many times a specific
             */
            if ($compilationContext->config->get('call-gatherer-pass', 'optimizations')) {
                $callGathererPass = new CallGathererPass($compilationContext);
                $callGathererPass->pass($this->_statements);
            } else {
                $callGathererPass = null;
            }

        } else {
            $localContext = null;
            $typeInference = null;
            $callGathererPass = null;
        }

        /**
         * Every method has its own symbol table
         */
        $symbolTable = new SymbolTable($compilationContext);
        if ($localContext) {
            $symbolTable->setLocalContext($localContext);
        }

        /**
         * Parameters has an additional extra mutation
         */
        $parameters = $this->_parameters;
        if ($localContext) {
            if (is_object($parameters)) {
                foreach ($parameters->getParameters() as $parameter) {
                    $localContext->increaseMutations($parameter['name']);
                }
            }
        }

        /**
         * Initialization of parameters happens in a fictitious external branch
         */
        $branch = new Branch();
        $branch->setType(Branch::TYPE_EXTERNAL);

        /**
         * BranchManager helps to create graphs of conditional/loop/root/jump branches
         */
        $branchManager = new BranchManager();
        $branchManager->addBranch($branch);

        /**
         * Cache Manager manages both function and method call caches
         */
        $cacheManager = new CacheManager();
        $cacheManager->setGatherer($callGathererPass);

        $compilationContext->branchManager = $branchManager;
        $compilationContext->cacheManager  = $cacheManager;
        $compilationContext->typeInference = $typeInference;
        $compilationContext->symbolTable   = $symbolTable;

        $oldCodePrinter = $compilationContext->codePrinter;

        /**
         * Change the code printer to a single method instance
         */
        $codePrinter = new CodePrinter();
        $compilationContext->codePrinter = $codePrinter;

        /**
         * Set an empty function cache
         */
        $compilationContext->functionCache = null;

        /**
         * Reset try/catch and loop counter
         */
        $compilationContext->insideCycle = 0;
        $compilationContext->insideTryCatch = 0;

        if (is_object($parameters)) {

            /**
             * Round 1. Create variables in parameters in the symbol table
             */
            $classCastChecks = array();
            foreach ($parameters->getParameters() as $parameter) {

                /**
                 * Change dynamic variables to low level types
                 */
                if ($typeInference) {
                    if (isset($parameter['data-type'])) {
                        if ($parameter['data-type'] == 'variable') {
                            $type = $typeInference->getInferedType($parameter['name']);
                            if (is_string($type)) {
                                /* promote polymorphic parameters to low level types */
                            }
                        }
                    } else {
                        $type = $typeInference->getInferedType($parameter['name']);
                        if (is_string($type)) {
                            /* promote polymorphic parameters to low level types */
                        }
                    }
                }

                $symbolParam = null;
                if (isset($parameter['data-type'])) {
                    switch($parameter['data-type']) {
                        case 'object':
                        case 'callable':
                        case 'resource':
                        case 'variable':
                            $symbol = $symbolTable->addVariable($parameter['data-type'], $parameter['name'], $compilationContext);
                            break;
                        default:
                            $symbol = $symbolTable->addVariable($parameter['data-type'], $parameter['name'], $compilationContext);
                            $symbolParam = $symbolTable->addVariable('variable', $parameter['name'] . '_param', $compilationContext);
                            if ($parameter['data-type'] == 'string' || $parameter['data-type'] == 'array') {
                                $symbol->setMustInitNull(true);
                            }
                            break;
                    }
                } else {
                    $symbol = $symbolTable->addVariable('variable', $parameter['name'], $compilationContext);
                }

                /**
                 * Some parameters can be read-only
                 */
                if (isset($parameter['const']) && $parameter['const']) {
                    $symbol->setReadOnly(true);
                    if (is_object($symbolParam)) {
                        $symbolParam->setReadOnly(true);
                    }
                }

                if (is_object($symbolParam)) {

                    /**
                     * Parameters are marked as 'external'
                     */
                    $symbolParam->setIsExternal(true);

                    /**
                     * Assuming they're initialized
                     */
                    $symbolParam->setIsInitialized(true, $compilationContext, $parameter);

                    /**
                     * Initialize auxiliar parameter zvals to null
                     */
                    $symbolParam->setMustInitNull(true);

                    /**
                     * Increase uses
                     */
                    $symbolParam->increaseUses();

                } else {
                    if (isset($parameter['default'])) {
                        if (isset($parameter['data-type'])) {
                            if ($parameter['data-type'] == 'variable') {
                                $symbol->setMustInitNull(true);
                            }
                        } else {
                            $symbol->setMustInitNull(true);
                        }
                    }
                }

                /**
                 * Original node where the variable was declared
                 */
                $symbol->setOriginal($parameter);

                /**
                 * Parameters are marked as 'external'
                 */
                $symbol->setIsExternal(true);

                /**
                 * Assuming they're initialized
                 */
                $symbol->setIsInitialized(true, $compilationContext, $parameter);

                /**
                 * Variables with class/type must be objects across the execution
                 */
                if (isset($parameter['cast'])) {
                    $symbol->setDynamicTypes('object');
                    $symbol->setClassTypes($compilationContext->getFullName($parameter['cast']['value']));
                    $classCastChecks[] = array($symbol, $parameter);
                } else {
                    if (isset($parameter['data-type'])) {
                        if ($parameter['data-type'] == 'variable') {
                            $symbol->setDynamicTypes('undefined');
                        }
                    } else {
                        $symbol->setDynamicTypes('undefined');
                    }
                }
            }

            $compilationContext->codePrinter->increaseLevel();

            /**
             * Checks that a class-hinted variable meets its declaration
             */
            foreach ($classCastChecks as $classCastCheck) {
                foreach ($classCastCheck[0]->getClassTypes() as $className) {

                    /**
                     * If the parameter is nullable check it must pass the 'instanceof' validation
                     */
                    if (!isset($classCastCheck[1]['default'])) {
                        $evalExpr = new UnaryOperatorBuilder(
                            'not',
                            new BinaryOperatorBuilder(
                                'instanceof',
                                new VariableBuilder($classCastCheck[0]->getName()),
                                new VariableBuilder('\\' . $className)
                            )
                        );
                    } else {
                        $evalExpr = new BinaryOperatorBuilder(
                            'and',
                            new BinaryOperatorBuilder(
                                'not-equals',
                                new TypeOfOperatorBuilder(new VariableBuilder($classCastCheck[0]->getName())),
                                new LiteralBuilder("string", "null")
                            ),
                            new UnaryOperatorBuilder(
                                'not',
                                new BinaryOperatorBuilder(
                                    'instanceof',
                                    new VariableBuilder($classCastCheck[0]->getName()),
                                    new VariableBuilder('\\' . $className)
                                )
                            )
                        );
                    }

                    $ifCheck = new IfStatementBuilder(
                        $evalExpr,
                        new StatementsBlockBuilder(array(
                            new ThrowStatementBuilder(
                                new NewInstanceOperatorBuilder('\InvalidArgumentException', array(
                                    new ParameterBuilder(
                                        new LiteralBuilder(
                                            "string",
                                            "Parameter '" . $classCastCheck[0]->getName() . "' must be an instance of '" . Utils::addSlashes($className, true) . "'"
                                        )
                                    )
                                ))
                            )
                        ))
                    );

                    $ifStatement = new IfStatement($ifCheck->get());
                    $ifStatement->compile($compilationContext);
                }
            }

            $compilationContext->codePrinter->decreaseLevel();
        }

        /**
         * Compile the block of statements if any
         */
        if (is_object($this->_statements)) {

            if ($this->hasModifier('static')) {
                $compilationContext->staticContext = true;
            } else {
                $compilationContext->staticContext = false;
            }

            /**
             * Compile the statements block as a 'root' branch
             */
            $this->_statements->compile($compilationContext, false, Branch::TYPE_ROOT);
        }

        /**
         * Initialize default values in dynamic variables
         */
        $initVarCode = "";
        foreach ($symbolTable->getVariables() as $variable) {

            /**
             * Initialize 'dynamic' variables with default values
             */
            if ($variable->getType() == 'variable') {
                if ($variable->getNumberUses() > 0) {
                    if ($variable->getName() != 'this_ptr' && $variable->getName() != 'return_value') {
                        $defaultValue = $variable->getDefaultInitValue();
                        if (is_array($defaultValue)) {
                            $symbolTable->mustGrownStack(true);
                            switch ($defaultValue['type']) {

                                case 'int':
                                case 'uint':
                                case 'long':
                                case 'char':
                                case 'uchar':
                                    $initVarCode .= "\t" . 'ZEPHIR_INIT_VAR(' . $variable->getName() . ');' . PHP_EOL;
                                    $initVarCode .= "\t" . 'ZVAL_LONG(' . $variable->getName() . ', ' . $defaultValue['value'] . ');' . PHP_EOL;
                                    break;

                                case 'null':
                                    $initVarCode .= "\t" . 'ZEPHIR_INIT_VAR(' . $variable->getName() . ');' . PHP_EOL;
                                    $initVarCode .= "\t" . 'ZVAL_NULL(' . $variable->getName() . ');' . PHP_EOL;
                                    break;

                                case 'double':
                                    $initVarCode .= "\t" . 'ZEPHIR_INIT_VAR(' . $variable->getName() . ');' . PHP_EOL;
                                    $initVarCode .= "\t" . 'ZVAL_DOUBLE(' . $variable->getName() . ', ' . $defaultValue['value'] . ');' . PHP_EOL;
                                    break;

                                case 'string':
                                    $initVarCode .= "\t" . 'ZEPHIR_INIT_VAR(' . $variable->getName() . ');' . PHP_EOL;
                                    $initVarCode .= "\t" . 'ZVAL_STRING(' . $variable->getName() . ', "' . $defaultValue['value'] . '", 1);' . PHP_EOL;
                                    break;

                                case 'array':
                                case 'empty-array':
                                    $initVarCode .= "\t" . 'ZEPHIR_INIT_VAR(' . $variable->getName() . ');' . PHP_EOL;
                                    $initVarCode .= "\t" . 'array_init(' . $variable->getName() . ');' . PHP_EOL;
                                    break;

                                default:
                                    throw new CompilerException('Invalid default type: ' . $defaultValue['type'] . ' for data type: ' . $variable->getType(), $variable->getOriginal());
                            }
                        }
                    }
                }
                continue;
            }

            /**
             * Initialize 'string' variables with default values
             */
            if ($variable->getType() == 'string') {
                if ($variable->getNumberUses() > 0) {
                    $defaultValue = $variable->getDefaultInitValue();
                    if (is_array($defaultValue)) {
                        $symbolTable->mustGrownStack(true);
                        switch ($defaultValue['type']) {

                            case 'string':
                                $initVarCode .= "\t" . 'ZEPHIR_INIT_VAR(' . $variable->getName() . ');' . PHP_EOL;
                                $initVarCode .= "\t" . 'ZVAL_STRING(' . $variable->getName() . ', "' . $defaultValue['value'] . '", 1);' . PHP_EOL;
                                break;

                            case 'null':
                                $initVarCode .= "\t" . 'ZEPHIR_INIT_VAR(' . $variable->getName() . ');' . PHP_EOL;
                                $initVarCode .= "\t" . 'ZVAL_EMPTY_STRING(' . $variable->getName() . ');' . PHP_EOL;
                                break;

                            default:
                                throw new CompilerException('Invalid default type: ' . $defaultValue['type'] . ' for data type: ' . $variable->getType(), $variable->getOriginal());
                        }
                    }
                }
                continue;
            }

            /**
             * Initialize 'array' variables with default values
             */
            if ($variable->getType() == 'array') {
                if ($variable->getNumberUses() > 0) {
                    $defaultValue = $variable->getDefaultInitValue();
                    if (is_array($defaultValue)) {
                        $symbolTable->mustGrownStack(true);
                        switch ($defaultValue['type']) {

                            case 'null':
                                $initVarCode .= "\t" . 'ZEPHIR_INIT_VAR(' . $variable->getName() . ');' . PHP_EOL;
                                $initVarCode .= "\t" . 'array_init(' . $variable->getName() . ');' . PHP_EOL;
                                break;

                            case 'array':
                                $initVarCode .= "\t" . 'ZEPHIR_INIT_VAR(' . $variable->getName() . ');' . PHP_EOL;
                                $initVarCode .= "\t" . 'array_init(' . $variable->getName() . ');' . PHP_EOL;
                                break;

                            default:
                                throw new CompilerException('Invalid default type: ' . $defaultValue['type'] . ' for data type: ' . $variable->getType(), $variable->getOriginal());
                        }
                    }
                }
            }

        }

        /**
         * Fetch parameters from vm-top
         */
        $initCode = "";
        $code = "";
        if (is_object($parameters)) {

            /**
             * Round 2. Fetch the parameters in the method
             */
            $params = array();
            $requiredParams = array();
            $optionalParams = array();
            $numberRequiredParams = 0;
            $numberOptionalParams = 0;
            foreach ($parameters->getParameters() as $parameter) {

                if (isset($parameter['data-type'])) {
                    $dataType = $parameter['data-type'];
                } else {
                    $dataType = 'variable';
                }

                switch($dataType) {
                    case 'object':
                    case 'callable':
                    case 'resource':
                    case 'variable':
                        $params[] = '&' . $parameter['name'];
                        break;
                    default:
                        $params[] = '&' . $parameter['name'] . '_param';
                        break;
                }

                if (isset($parameter['default'])) {
                    $optionalParams[] = $parameter;
                    $numberOptionalParams++;
                } else {
                    $requiredParams[] = $parameter;
                    $numberRequiredParams++;
                }
            }

            /**
             * Pass the write detector to the method statement block to check if the parameter
             * variable is modified so as do the proper separation
             */
            $parametersToSeparate = array();
            if (is_object($this->_statements)) {

                /**
                 * If local context is not available
                 */
                if (!$localContext) {
                    $writeDetector = new WriteDetector();
                }

                foreach ($parameters->getParameters() as $parameter) {

                    if (isset($parameter['data-type'])) {
                        $dataType = $parameter['data-type'];
                    } else {
                        $dataType = 'variable';
                    }

                    switch ($dataType) {
                        case 'variable':
                        case 'string':
                        case 'array':
                        case 'resource':
                        case 'object':
                        case 'callable':
                            $name = $parameter['name'];
                            if (!$localContext) {
                                if ($writeDetector->detect($name, $this->_statements->getStatements())) {
                                    $parametersToSeparate[$name] = true;
                                }
                            } else {
                                if ($localContext->getNumberOfMutations($name) > 1) {
                                    $parametersToSeparate[$name] = true;
                                }
                            }
                            break;
                    }
                }
            }

            /**
             * Initialize required parameters
             */
            foreach ($requiredParams as $parameter) {

                if (isset($parameter['mandatory'])) {
                    $mandatory = $parameter['mandatory'];
                } else {
                    $mandatory = 0;
                }

                if (isset($parameter['data-type'])) {
                    $dataType = $parameter['data-type'];
                } else {
                    $dataType = 'variable';
                }

                if ($dataType != 'variable') {

                    /**
                     * Assign value from zval to low level type
                     */
                    if ($mandatory) {
                        $initCode .= $this->checkStrictType($parameter, $compilationContext);
                    } else {
                        $initCode .= $this->assignZvalValue($parameter, $compilationContext);
                    }
                }

                switch ($dataType) {
                    case 'variable':
                    case 'string':
                    case 'array':
                    case 'resource':
                    case 'object':
                    case 'callable':
                        if (isset($parametersToSeparate[$parameter['name']])) {
                            $symbolTable->mustGrownStack(true);
                            $initCode .= "\t" . "ZEPHIR_SEPARATE_PARAM(" . $parameter['name'] . ");" . PHP_EOL;
                        }
                        break;
                }
            }

            /**
             * Initialize optional parameters
             */
            foreach ($optionalParams as $parameter) {
                if (isset($parameter['mandatory'])) {
                    $mandatory = $parameter['mandatory'];
                } else {
                    $mandatory = 0;
                }

                if (isset($parameter['data-type'])) {
                    $dataType = $parameter['data-type'];
                } else {
                    $dataType = 'variable';
                }

                switch($dataType) {
                    case 'object':
                    case 'callable':
                    case 'resource':
                    case 'variable':
                        $name = $parameter['name'];
                        break;
                    default:
                        $name = $parameter['name'] . '_param';
                        break;
                }

                /**
                 * Assign the default value according to the variable's type
                 */
                $initCode .= "\t" . 'if (!' . $name . ') {' . PHP_EOL;
                $initCode .= $this->assignDefaultValue($parameter, $compilationContext);

                if (isset($parametersToSeparate[$name]) || $dataType != 'variable') {
                    $initCode .= "\t" . '} else {' . PHP_EOL;
                    if (isset($parametersToSeparate[$name])) {
                        $initCode .= "\t\t" . "ZEPHIR_SEPARATE_PARAM(" . $name . ");" . PHP_EOL;
                    } else {
                        if ($mandatory) {
                            $initCode .= $this->checkStrictType($parameter, $compilationContext, $mandatory);
                        } else {
                            $initCode .= "\t".$this->assignZvalValue($parameter, $compilationContext);
                        }
                    }
                }
                $initCode .= "\t" . '}' . PHP_EOL;
            }

            /**
             * Fetch the parameters to zval pointers
             */
            $codePrinter->preOutputBlankLine();
            $compilationContext->headersManager->add('kernel/memory');
            if ($symbolTable->getMustGrownStack()) {
                $code .= "\t" . 'zephir_fetch_params(1, ' . $numberRequiredParams . ', ' . $numberOptionalParams . ', ' . join(', ', $params) . ');' . PHP_EOL;
            } else {
                $code .= "\t" . 'zephir_fetch_params(0, ' . $numberRequiredParams . ', ' . $numberOptionalParams . ', ' . join(', ', $params) . ');' . PHP_EOL;
            }
            $code .= PHP_EOL;
        }

        $code .= $initCode . $initVarCode;
        $codePrinter->preOutput($code);

        /**
         * Grow the stack if needed
         */
        if ($symbolTable->getMustGrownStack()) {
            $compilationContext->headersManager->add('kernel/memory');
            $codePrinter->preOutput("\t" . 'ZEPHIR_MM_GROW();');
        }

        /**
         * Check if there are unused variables
         */
        $usedVariables = array();
        $completeName = $compilationContext->classDefinition->getCompleteName();
        foreach ($symbolTable->getVariables() as $variable) {

            if ($variable->getNumberUses() <= 0) {
                if ($variable->isExternal() == false) {
                    $compilationContext->logger->warning('Variable "' . $variable->getName() . '" declared but not used in ' . $completeName . '::' . $this->getName(), "unused-variable", $variable->getOriginal());
                    continue;
                }
                $compilationContext->logger->warning('Variable "' . $variable->getName() . '" declared but not used in ' . $completeName . '::' . $this->getName(), "unused-variable-external", $variable->getOriginal());
            }

            if ($variable->getName() != 'this_ptr' && $variable->getName() != 'return_value') {
                $type = $variable->getType();
                if (!isset($usedVariables[$type])) {
                    $usedVariables[$type] = array();
                }
                $usedVariables[$type][] = $variable;
            }
        }

        if (count($usedVariables)) {
            $codePrinter->preOutputBlankLine();
        }

        /**
         * Generate the variable definition for variables used
         */
        foreach ($usedVariables as $type => $variables) {

            $pointer = null;
            switch ($type) {

                case 'int':
                    $code = 'int ';
                    break;

                case 'uint':
                    $code = 'unsigned int ';
                    break;

                case 'char':
                    $code = 'char ';
                    break;

                case 'uchar':
                    $code = 'unsigned char ';
                    break;

                case 'long':
                    $code = 'long ';
                    break;

                case 'ulong':
                    $code = 'unsigned long ';
                    break;

                case 'bool':
                    $code = 'zend_bool ';
                    break;

                case 'double':
                    $code = 'double ';
                    break;

                case 'string':
                case 'variable':
                case 'array':
                case 'null':
                    $pointer = '*';
                    $code = 'zval ';
                    break;

                case 'HashTable':
                    $pointer = '*';
                    $code = 'HashTable ';
                    break;

                case 'HashPosition':
                    $code = 'HashPosition ';
                    break;

                case 'zend_class_entry':
                    $pointer = '*';
                    $code = 'zend_class_entry ';
                    break;

                case 'zend_function':
                    $pointer = '*';
                    $code = 'zend_function ';
                    break;

                case 'zend_object_iterator':
                    $pointer = '*';
                    $code = 'zend_object_iterator ';
                    break;

                case 'zend_property_info':
                    $pointer = '*';
                    $code = 'zend_property_info ';
                    break;

                case 'zephir_fcall_cache_entry':
                    $pointer = '*';
                    $code = 'zephir_fcall_cache_entry ';
                    break;

                case 'static_zephir_fcall_cache_entry':
                    $pointer = '*';
                    $code = 'zephir_nts_static zephir_fcall_cache_entry ';
                    break;

                default:
                    throw new CompilerException("Unsupported type in declare: " . $type);
            }

            $groupVariables = array();
            $defaultValues = array();

            /**
             * @var $variables Variable[]
             */
            foreach ($variables as $variable) {
                if (($type == 'variable' || $type == 'string' || $type == 'array' || $type == 'resource' || $type == 'callable' || $type == 'object') && $variable->mustInitNull()) {
                    if ($variable->isLocalOnly()) {
                        $groupVariables[] = $variable->getName() . ' = zval_used_for_init';
                    } else {
                        if ($variable->isDoublePointer()) {
                            $groupVariables[] = $pointer . $pointer . $variable->getName() . ' = NULL';
                        } else {
                            $groupVariables[] = $pointer . $variable->getName() . ' = NULL';
                        }
                    }
                } else {
                    if ($variable->isLocalOnly()) {
                        $groupVariables[] = $variable->getName();
                    } else {
                        if ($variable->isDoublePointer()) {
                            if ($variable->mustInitNull()) {
                                $groupVariables[] = $pointer . $pointer . $variable->getName() . ' = NULL';
                            } else {
                                $groupVariables[] = $pointer . $pointer . $variable->getName();
                            }
                        } else {
                            $defaultValue = $variable->getDefaultInitValue();
                            if ($defaultValue !== null) {
                                switch($type) {
                                    case 'variable':
                                    case 'string':
                                    case 'array':
                                    case 'resource':
                                    case 'callable':
                                    case 'object':
                                        $groupVariables[] = $pointer . $variable->getName();
                                        break;
                                    default:
                                        $groupVariables[] = $pointer . $variable->getName() . ' = ' . $defaultValue;
                                        break;
                                }
                            } else {
                                if ($variable->mustInitNull() && $pointer) {
                                    $groupVariables[] = $pointer . $variable->getName() . ' = NULL';
                                } else {
                                    $groupVariables[] = $pointer . $variable->getName();
                                }
                            }
                        }
                    }
                }
            }

            $codePrinter->preOutput("\t" . $code . join(', ', $groupVariables) . ';');
        }

        /**
         * Finalize the method compilation
         */
        if (is_object($this->_statements)) {

            /**
             * If the last statement is not a 'return' or 'throw' we need to
             * restore the memory stack if needed
             */
            $lastType = $this->_statements->getLastStatementType();

            if ($lastType != 'return' && $lastType != 'throw') {

                if ($symbolTable->getMustGrownStack()) {
                    $compilationContext->headersManager->add('kernel/memory');
                    $codePrinter->output("\t" . 'ZEPHIR_MM_RESTORE();');
                }

                /**
                 * If a method has return-type hints we need to ensure the last statement is a 'return' statement
                 */
                if ($this->hasReturnTypes()) {
                    throw new CompilerException('Reached end of the method without returning a valid type specified in the return-type hints', $this->_expression['return-type']);
                }
            }
        }

        /**
         * Remove macros that restore the memory stack if it wasn't used
         */
        $code = $this->removeMemoryStackReferences($symbolTable, $codePrinter->getOutput());

        /**
         * Restore the compilation context
         */
        $oldCodePrinter->output($code);
        $compilationContext->codePrinter = $oldCodePrinter;

        $compilationContext->branchManager = null;
        $compilationContext->cacheManager = null;
        $compilationContext->typeInference = null;

        $codePrinter->clear();

        return null;
    }
}
