<?php
namespace TYPO3Fluid\Fluid\Core\Parser\SyntaxTree;

/*
 * This file belongs to the package "TYPO3 Fluid".
 * See LICENSE.txt that was shipped with this package.
 */

use TYPO3Fluid\Fluid\Component\Argument\ArgumentCollectionInterface;
use TYPO3Fluid\Fluid\Core\Parser\Exception;
use TYPO3Fluid\Fluid\Core\Parser\ParsingState;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperInterface;

/**
 * Node which will call a ViewHelper associated with this node.
 */
class NamespacedNode extends AbstractNode
{
    /**
     * @var NodeInterface[]
     */
    protected $arguments = [];

    /**
     * @var RenderingContextInterface
     */
    protected $renderingContext;

    /**
     * @var ArgumentDefinition[]
     */
    protected $argumentDefinitions = [];

    /**
     * @var string
     */
    protected $pointerTemplateCode = null;

    /**
     * @var array
     */
    protected $parsingPointers = [];

    /**
     * @var string
     */
    protected $namespace;

    /**
     * @var string
     */
    protected $identifier;

    /**
     * Constructor.
     *
     * @param RenderingContextInterface $renderingContext a RenderingContext, provided by invoker
     * @param string $namespace the namespace identifier of the ViewHelper.
     * @param string $identifier the name of the ViewHelper to render, inside the namespace provided.
     * @param NodeInterface[] $arguments Arguments of view helper - each value is a RootNode.
     * @param ParsingState $state
     */
    public function __construct(RenderingContextInterface $renderingContext, $namespace, $identifier, array $arguments, ParsingState $state)
    {
        $this->namespace = $namespace;
        $this->identifier = $identifier;
        $this->arguments = $arguments;
        $this->renderingContext = $renderingContext;
        $this->parsingPointers = $renderingContext->getTemplateParser()->getCurrentParsingPointers();
    }

    /**
     * @return string
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return RenderingContextInterface
     */
    public function getRenderingContext(): RenderingContextInterface
    {
        return $this->renderingContext;
    }

    /**
     * @return ArgumentDefinition[]
     */
    public function getArgumentDefinitions()
    {
        return $this->argumentDefinitions;
    }

    /**
     * Returns the attached (but still uninitialized) ViewHelper for this ViewHelperNode.
     * We need this method because sometimes Interceptors need to ask some information from the ViewHelper.
     *
     * @return ViewHelperInterface
     */
    public function getUninitializedViewHelper()
    {
        return $this->uninitializedViewHelper;
    }

    /**
     * Get class name of view helper
     *
     * @return string Class Name of associated view helper
     */
    public function getViewHelperClassName()
    {
        return $this->viewHelperClassName;
    }

    /**
     * INTERNAL - only needed for compiling templates
     *
     * @return NodeInterface[]
     */
    public function getArguments()
    {
        return $this->arguments;
    }

    /**
     * INTERNAL - only needed for compiling templates
     *
     * @param string $argumentName
     * @return ArgumentDefinition
     */
    public function getArgumentDefinition($argumentName)
    {
        return $this->argumentDefinitions[$argumentName];
    }

    /**
     * @param NodeInterface $childNode
     * @return void
     */
    public function addChildNode(NodeInterface $childNode)
    {
        parent::addChildNode($childNode);
        $this->uninitializedViewHelper->setChildNodes($this->childNodes);
    }

    /**
     * @param string $pointerTemplateCode
     * @return void
     */
    public function setPointerTemplateCode($pointerTemplateCode)
    {
        $this->pointerTemplateCode = $pointerTemplateCode;
    }

    /**
     * Call the view helper associated with this object.
     *
     * First, it evaluates the arguments of the view helper.
     *
     * If the view helper implements \TYPO3Fluid\Fluid\Core\ViewHelper\ChildNodeAccessInterface,
     * it calls setChildNodes(array childNodes) on the view helper.
     *
     * Afterwards, checks that the view helper did not leave a variable lying around.
     *
     * @param RenderingContextInterface $renderingContext
     * @param ArgumentCollectionInterface|null $arguments
     * @return string evaluated node after the view helper has been called.
     */
    public function evaluate(RenderingContextInterface $renderingContext, ?ArgumentCollectionInterface $arguments = null)
    {
        return $renderingContext->getViewHelperInvoker()->invoke($this->uninitializedViewHelper, $this->arguments, $renderingContext);
    }

    /**
     * Wraps the argument tree, if a node is boolean, into a Boolean syntax tree node
     *
     * @param ArgumentDefinition[] $argumentDefinitions the argument definitions, key is the argument name, value is the ArgumentDefinition object
     * @param NodeInterface[] $argumentsObjectTree the arguments syntax tree, key is the argument name, value is an AbstractNode
     * @return void
     */
    protected function rewriteBooleanNodesInArgumentsObjectTree($argumentDefinitions, &$argumentsObjectTree)
    {
        /** @var $argumentDefinition ArgumentDefinition */
        foreach ($argumentDefinitions as $argumentName => $argumentDefinition) {
            if (($argumentDefinition->getType() === 'boolean' || $argumentDefinition->getType() === 'bool')
                && isset($argumentsObjectTree[$argumentName])) {
                $argumentsObjectTree[$argumentName] = new BooleanNode($argumentsObjectTree[$argumentName]);
            }
        }
    }

    /**
     * @param ArgumentDefinition[] $argumentDefinitions
     * @param NodeInterface[] $argumentsObjectTree
     * @throws Exception
     */
    protected function validateArguments(array $argumentDefinitions, array $argumentsObjectTree)
    {
        $additionalArguments = [];
        foreach ($argumentsObjectTree as $argumentName => $value) {
            if (!array_key_exists($argumentName, $argumentDefinitions)) {
                $additionalArguments[$argumentName] = $value;
            }
        }
        $this->abortIfRequiredArgumentsAreMissing($argumentDefinitions, $argumentsObjectTree);
        $this->uninitializedViewHelper->validateAdditionalArguments($additionalArguments);
    }

    /**
     * Throw an exception if required arguments are missing
     *
     * @param ArgumentDefinition[] $expectedArguments Array of all expected arguments
     * @param NodeInterface[] $actualArguments Actual arguments
     * @throws Exception
     */
    protected function abortIfRequiredArgumentsAreMissing($expectedArguments, $actualArguments)
    {
        $actualArgumentNames = array_keys($actualArguments);
        foreach ($expectedArguments as $expectedArgument) {
            if ($expectedArgument->isRequired() && !in_array($expectedArgument->getName(), $actualArgumentNames)) {
                throw new Exception('Required argument "' . $expectedArgument->getName() . '" was not supplied.', 1237823699);
            }
        }
    }
}
