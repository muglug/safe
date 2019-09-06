<?php
namespace Safe;

use Safe\PhpStanFunctions\PhpStanFunction;

class Parameter
{
    /**
     * @var \SimpleXMLElement
     */
    private $parameter;
    /**
     * @var PhpStanFunction|null
     */
    private $phpStanFunction;

    public function __construct(\SimpleXMLElement $parameter, ?PhpStanFunction $phpStanFunction)
    {
        $this->parameter = $parameter;
        $this->phpStanFunction = $phpStanFunction;
    }

    /**
     * Tries to identify the typehint from the doc-block parameter
     */
    public function getSignatureType(): string
    {
        $coreType = $this->getDocBlockType();
        //list all types in the doc-block
        $types = explode('|', $coreType);
        //no typehint exists for thoses cases
        if (in_array('resource', $types) || in_array('mixed', $types)) {
            return '';
        }
        //remove 'null' from the list to identify if the signature type should be nullable
        $nullablePosition = array_search('null', $types);
        if ($nullablePosition !== false) {
            array_splice($types, $nullablePosition, 1);
        }
        if (count($types) === 0) {
            throw new \RuntimeException('Error when trying to extract parameter type');
        }
        //if there is still several types, no typehint
        if (count($types) > 1) {
            return '';
        }

        $finalType = $types[0];
        //strip callable type of its possible parenthesis and return (ex: callable(): void)
        if (\strpos($finalType, 'callable(') > -1) {
            $finalType = 'callable';
        } elseif (strpos($finalType, '[]') !== false) {
            $finalType = 'iterable'; //generics cannot be typehinted and have to be turned into iterable
        }
        return ($nullablePosition !== false ? '?' : '').$finalType;
    }

    /**
     * Try to fetch the complete type used in the doc_block, first from phpstan, then from the regular documentation
     */
    public function getDocBlockType(): string
    {
        if ($this->phpStanFunction !== null) {
            $phpStanParameter = $this->phpStanFunction->getParameter($this->getParameter());
            if ($phpStanParameter) {
                try {
                    $type = $phpStanParameter->getType();
                    // Let's make the parameter nullable if it is by reference and is used only for writing.
                    if ($phpStanParameter->isWriteOnly() && $type !== 'resource' && $type !== 'mixed') {
                        $type = $type.'|null';
                    }
                    return $type;
                } catch (EmptyTypeException $e) {
                    // If the type is empty in PHPStan, we fallback to documentation.
                    // @ignoreException
                }
            }
        }

        $type = $this->parameter->type->__toString();
        return Type::toRootNamespace($type);
    }

    public function getParameter(): string
    {
        if ($this->isVariadic()) {
            return 'params';
        }
        // The db2_bind_param method has parameters with a dash in it... yep... (patch submitted)
        return \str_replace('-', '_', $this->parameter->parameter->__toString());
    }

    public function isByReference(): bool
    {
        return ((string)$this->parameter->parameter['role']) === 'reference';
    }

    /**
     * Some parameters can be optional with no default value. In this case, the function is "overloaded" (which is not
     * possible in user-land but possible in core...)
     *
     * @return bool
     */
    public function isOptionalWithNoDefault(): bool
    {
        if (((string)$this->parameter['choice']) !== 'opt') {
            return false;
        }
        if (!$this->hasDefaultValue()) {
            return true;
        }

        $initializer = $this->getInitializer();
        // Some default value have weird values. For instance, first parameter of "mb_internal_encoding" has default value "mb_internal_encoding()"
        if ($initializer !== 'array()' && strpos($initializer, '(') !== false) {
            return true;
        }
        return false;
    }

    public function isVariadic(): bool
    {
        return $this->parameter->parameter->__toString() === '...';
    }

    public function isNullable(): bool
    {
        if ($this->phpStanFunction !== null) {
            $phpStanParameter = $this->phpStanFunction->getParameter($this->getParameter());
            if ($phpStanParameter) {
                return $phpStanParameter->isNullable();
            }
        }
        return $this->hasDefaultValue() && $this->getDefaultValue() === 'null';
    }

    /*
     * @return string
     */
    public function getInitializer(): string
    {
        return \str_replace(['<constant>', '</constant>'], '', $this->getInnerXml($this->parameter->initializer));
    }

    public function hasDefaultValue(): bool
    {
        return isset($this->parameter->initializer);
    }

    public function getDefaultValue(): ?string
    {
        if (!$this->hasDefaultValue()) {
            return null;
        }

        $initializer = $this->getInitializer();

        // Some default value have weird values. For instance, first parameter of "mb_internal_encoding" has default value "mb_internal_encoding()"
        if (strpos($initializer, '(') !== false) {
            return null;
        }

        return $initializer;
    }

    private function getInnerXml(\SimpleXMLElement $SimpleXMLElement): string
    {
        $element_name = $SimpleXMLElement->getName();
        $inner_xml = $SimpleXMLElement->asXML();
        if ($inner_xml === false) {
            throw new \RuntimeException('Unable to serialize to XML');
        }
        $inner_xml = str_replace(['<'.$element_name.'>', '</'.$element_name.'>', '<'.$element_name.'/>'], '', $inner_xml);
        $inner_xml = trim($inner_xml);
        return $inner_xml;
    }
}
