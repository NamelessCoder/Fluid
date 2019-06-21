<?php
namespace TYPO3Fluid\Fluid\Tests\Unit\Core\Parser;

/*
 * This file belongs to the package "TYPO3 Fluid".
 * See LICENSE.txt that was shipped with this package.
 */

use TYPO3Fluid\Fluid\Core\ErrorHandler\StandardErrorHandler;
use TYPO3Fluid\Fluid\Core\ErrorHandler\TolerantErrorHandler;
use TYPO3Fluid\Fluid\Core\Parser\Configuration;
use TYPO3Fluid\Fluid\Core\Parser\Exception;
use TYPO3Fluid\Fluid\Core\Parser\Interceptor\Escape;
use TYPO3Fluid\Fluid\Core\Parser\ParsingState;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ArrayNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\EscapingNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\Expression\CastingExpressionNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\Expression\ExpressionNodeInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\Expression\MathExpressionNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\NodeInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ObjectAccessorNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\RootNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\TextNode;
use TYPO3Fluid\Fluid\Core\Parser\TemplateParser;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\Variables\VariableProviderInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolver;
use TYPO3Fluid\Fluid\Tests\UnitTestCase;
use TYPO3Fluid\Fluid\ViewHelpers\Format\RawViewHelper;
use TYPO3Fluid\Fluid\Tests\Unit\Core\Parser\Fixtures\ViewHelpers\CViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\LayoutViewHelper;


/**
 * Testcase for SequencedTemplateParser.
 */
class SequencerTest extends UnitTestCase
{
    /**
     * @test
     * @dataProvider getErrorExpectations
     * @param string $template
     * @param RootNode $expectedRootNode
     */
    public function failsWithExpectedSequencingException(string $template, RenderingContextInterface $context, int $expectedExceptionCode)
    {
        $this->setExpectedException(Exception::class, '', $expectedExceptionCode);
        $parser = new TemplateParser();
        $context->setTemplateParser($parser);
        $parser->setRenderingContext($context);
        $parser->parse($template)->getRootNode();
    }

    public function getErrorExpectations(): array
    {
        $context = $this->createContext();

        return [
            'Unterminated inline syntax' => [
                '{foo',
                $context,
                1557838506
            ],
            'Unexpected token in tag sequencing, inactive tag (EOF, null byte)' => [
                '<foo',
                $context,
                1557700786
            ],
            'Unexpected token in tag sequencing (EOF, null byte)' => [
                '<f:c',
                $context,
                1557700786
            ],
            'Unterminated expression inside quotes' => [
                '<f:c s="',
                $context,
                1557700793
            ],
            'Unterminated array with curly braces in active tag' => [
                '<f:c a="{foo:',
                $context,
                1557838506
            ],
            'Unterminated array with square brackets in active tag' => [
                '<f:c a="[foo:',
                $context,
                1557748574
            ],
            'Unsupported argument before equals sign in active tag' => [
                '<f:c foo="no" />',
                $context,
                1558298976
            ],
            'Quoted value without a key is not allowed in tags' => [
                '<f:c "foo" />',
                $context,
                1558952412
            ],
            'Mismatched closing tag' => [
                '<f:c></f:comment>',
                $context,
                1557700789
            ],
            'Unresolved ViewHelper as active self-closed tag' => [
                '<f:notfound />',
                $context,
                1407060572
            ],
            'Unresolved ViewHelper as active tag' => [
                '<f:notfound></f:notfound>',
                $context,
                1407060572
            ],
            'Unresolved ViewHelper as inline' => [
                '{f:notfound()}',
                $context,
                1407060572
            ],
            'Invalid inline syntax with Fluid-like symbols' => [
                '{- > foo}',
                $context,
                1558782228
            ],
            'Colon without preceding key' => [
                '{f:c(: 1)}',
                $context,
                1559250839
            ],
            'Equals sign without preceding key' => [
                '{f:c(="1")}',
                $context,
                1559250839
            ],
            'Unsupported argument in ViewHelper as inline' => [
                '{f:c(unsupported: 1)}',
                $context,
                1558298976
            ],
            'Unsupported key-less argument with subsequent valid argument in ViewHelper as inline' => [
                '{f:c(unsupported, s)}',
                $context,
                1558298976
            ],
            'Unsupported key-less argument in ViewHelper as inline' => [
                '{f:c(unsupported)}',
                $context,
                1558298976
            ],
            'Unexpected content before quote start in associative array' => [
                '<f:c a="{foo: {0: BAD"bar"})}',
                $context,
                1559145560
            ],
            'Unexpected content before array/inline start in associative array' => [
                '<f:c a="{foo: {0: BAD{bar: baz}"})}',
                $context,
                1559131849
            ],
            'Unexpected equals sign without preceding argument name or array key' => [
                '<f:c ="foo" />',
                $context,
                1561039838
            ],
            'Unexpected content before array/inline start in numeric array' => [
                '<f:c a="{foo: {0: BAD[bar, baz]}}" />',
                $context,
                1559131849
            ],
            'Unexpected array/inline start in associative array without preceding key' => [
                '<f:c a="{foo: {{foo: bar}: "value"}" />',
                $context,
                1559131848
            ],
            'Invalid cast (confirm expression error pass-through)' => [
                '{foo as invalid}',
                $context,
                1559248372
            ],
        ];
    }

    /**
     * @NOTtest
     */
    public function temporaryNotWorkingCase()
    {
        /*

        echo PHP_EOL;
        echo PHP_EOL;
        echo PHP_EOL;
        echo PHP_EOL;

        foreach ($this->getSequenceExpectations() as $label => $parts) {
            #echo $label . ':';
            #echo PHP_EOL;
            echo $label . ':';
            echo PHP_EOL;
            echo PHP_EOL;
            echo '* `' . $parts[0] . '`';
            echo PHP_EOL;
            echo PHP_EOL;
            #echo PHP_EOL;
        }
        exit();
        */
        list ($template, $context, $expectedRootNode) = $this->getSequenceExpectations()['simple inline ViewHelper with multiple values pipe in root context'];
        $parser = new TemplateParser();
        $context->setTemplateParser($parser);
        $parser->setRenderingContext($context);
        $node = $parser->parse($template)->getRootNode();
        $this->assertNodeEquals($node, $expectedRootNode);
    }

    /**
     * @test
     * @dataProvider getSequenceExpectations
     * @param string $template
     * @param RootNode $expectedRootNode
     */
    public function createsExpectedNodeStructure(string $template, RenderingContextInterface $context, RootNode $expectedRootNode)
    {
        $parser = new TemplateParser();
        $context->setTemplateParser($parser);
        $parser->setRenderingContext($context);
        $node = $parser->parse($template)->getRootNode();
        $this->assertNodeEquals($node, $expectedRootNode);
    }

    public function getSequenceExpectations(): array
    {
        $state = $this->createState();
        $context = $this->createContext();

        return [

            /* EMPTY */
            'empty source' => [
                '',
                $context,
                new RootNode()
            ],

            /* TAGS */
            'simple open+close non-active tag in root context' => [
                '<tag>x</tag>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag>x</tag>')),
            ],
            'simple open+close non-active tag with hardcoded attribute in root context' => [
                '<tag attr="foo">x</tag>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag attr="foo">x</tag>')),
            ],
            'simple open+close non-active tag with object accessor attribute value in root context' => [
                '<tag attr="{string}">x</tag>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag attr="'))->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new TextNode('">x</tag>')),
            ],
            'simple open+close non-active tag with value-less object accessor attribute in root context' => [
                '<tag {string}>x</tag>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag '))->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new TextNode('>x</tag>')),
            ],
            'simple open+close non-active tag with object accessor attribute name in root context' => [
                '<tag {string}="foo">x</tag>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag '))->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new TextNode('="foo">x</tag>')),
            ],
            'simple open+close non-active tag with object accessor attribute name and value in root context' => [
                '<tag {string}="{string}">x</tag>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag '))->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new TextNode('="'))->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new TextNode('">x</tag>')),
            ],
            'simple open+close non-active tag with unquoted object accessor attribute name and value in root context' => [
                '<tag {string}={string}>x</tag>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag '))->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new TextNode('='))->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new TextNode('>x</tag>')),
            ],
            'simple open+close non-active tag with unquoted object accessor attribute name and static value in root context' => [
                '<tag {string}=1>x</tag>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag '))->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new TextNode('=1>x</tag>')),
            ],
            'simple open+close non-active tag with value-less unquoted object accessor attribute name in root context' => [
                '<tag {string}>x</tag>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag '))->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new TextNode('>x</tag>')),
            ],
            'simple tag with inline ViewHelper and legacy pass to other ViewHelper as string argument value' => [
                '<tag s="{f:c() -> f:c()}">x</tag>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag s="'))->addChildNode((new CViewHelper())->postParse([], $state, $context)->addChildNode((new CViewHelper())->postParse([], $state, $context)))->addChildNode(new TextNode('">x</tag>')),
            ],
            'self-closed non-active, two value-less static attributes' => [
                '<tag static1 static2 />',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag static1 static2 />')),
            ],
            'self-closed non-active, space before closing tag, tag in root context' => [
                '<tag />',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag />')),
            ],
            'self-closed non-active, no space before closing tag, tag in root context' => [
                '<tag/>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag/>')),
            ],
            'self-closed non-active, dynamic tag name, no space before closing tag, tag in root context' => [
                '<{tag}/>',
                $context,
                (new RootNode())->addChildNode(new TextNode('<'))->addChildNode(new ObjectAccessorNode('tag'))->addChildNode(new TextNode('/>')),
            ],
            'simple open+close active tag in root context' => [
                '<f:c>x</f:c>',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->addChildNode(new TextNode('x'))->postParse([], $state, $context)),
            ],
            'simple open+close active tag with string parameter in root context' => [
                '<f:c s="foo">x</f:c>',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->addChildNode(new TextNode('x'))->postParse(['s' => 'foo'], $state, $context)),
            ],
            'self-closed active tag, space before tag close, in root context' => [
                '<f:c />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse([], $state, $context)),
            ],
            'self-closed active tag, no space before tag close, in root context' => [
                '<f:c/>',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse([], $state, $context)),
            ],
            'self-closed active tag with hardcoded string argument' => [
                '<f:c s="foo" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => 'foo'], $state, $context)),
            ],
            'self-closed active tag with hardcoded string argument with backslash that must not be removed/ignored' => [
                '<f:c s="foo\\bar" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => 'foo\\bar'], $state, $context)),
            ],
            'self-closed active tag with hardcoded string argument with multiple backslashes that must not be removed/ignored' => [
                '<f:c s="foo\\bar\\\\baz" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => 'foo\\bar\\\\baz'], $state, $context)),
            ],
            'self-closed active tag with hardcoded string and integer arguments' => [
                '<f:c s="foo" i="1" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => 'foo', 'i' => 1], $state, $context)),
            ],
            'self-closed active tag with object accessor string argument in string argument' => [
                '<f:c s="{string}" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => new ObjectAccessorNode('string')], $state, $context)),
            ],
            'self-closed active tag with object accessor string argument with string before accessor in string argument' => [
                '<f:c s="before {string}" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => (new RootNode())->addChildNode(new TextNode('before '))->addChildNode(new ObjectAccessorNode('string'))], $state, $context)),
            ],
            'self-closed active tag with object accessor string argument with string after accessor in string argument' => [
                '<f:c s="{string} after" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => (new RootNode())->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new TextNode(' after'))], $state, $context)),
                #(new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => new ObjectAccessorNode('string')], $state)),
            ],
            'self-closed active tag with object accessor string argument with string before and after accessor in string argument' => [
                '<f:c s="before {string} after" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => (new RootNode())->addChildNode(new TextNode('before '))->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new TextNode(' after'))], $state, $context)),
            ],
            'self-closed active tag with string argument containing different quotes inside in string argument' => [
                '<f:c s="before \'quoted\' after" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => 'before \'quoted\' after'], $state, $context)),
            ],
            'self-closed active tag with string argument containing escaped same type quotes inside in string argument' => [
                '<f:c s="before \\"quoted\\" after" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => 'before "quoted" after'], $state, $context)),
            ],
            'self-closed active tag with array argument with single unquoted element in array argument' => [
                '<f:c a="{foo:bar}" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => new ObjectAccessorNode('bar')])], $state, $context)),
            ],
            'self-closed active tag with array argument with two unquoted elements with comma and space in array argument' => [
                '<f:c a="{foo:bar, baz:honk}" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => new ObjectAccessorNode('bar'), 'baz' => new ObjectAccessorNode('honk')])], $state, $context), $context),
            ],
            'self-closed active tag with array argument with two unquoted elements with comma without space in array argument' => [
                '<f:c a="{foo:bar,baz:honk}" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => new ObjectAccessorNode('bar'), 'baz' => new ObjectAccessorNode('honk')])], $state, $context)),
            ],
            'self-closed active tag with array argument with single double-quoted element (no escaping) in array argument' => [
                '<f:c a="{foo:"bar"}" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => 'bar'])], $state, $context)),
            ],
            'self-closed active tag with array argument with single single-quoted element (no escaping) in array argument' => [
                '<f:c a="{foo:\'bar\'}" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => 'bar'])], $state, $context)),
            ],
            'self-closed active tag with array argument with single integer element in array argument' => [
                '<f:c a="{foo:1}" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => 1])], $state, $context)),
            ],
            'self-closed active tag with array argument with double-quoted key and single integer element in array argument' => [
                '<f:c a="{"foo":1}" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => 1])], $state, $context)),
            ],
            'self-closed active tag with array argument with square brackets and single double-quoted string element in array argument' => [
                '<f:c a="["foo"]" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo'])], $state, $context)),
            ],
            'self-closed active tag with array argument with square brackets and single double-quoted object accessor element in array argument' => [
                '<f:c a="["{foo}"]" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode([new ObjectAccessorNode('foo')])], $state, $context)),
            ],
            'self-closed active tag with array argument with square brackets and two-element ECMA literal array in array argument' => [
                '<f:c a="[{foo, bar}]" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode([new ArrayNode(['foo' => new ObjectAccessorNode('foo'), 'bar' => new ObjectAccessorNode('bar')])])], $state, $context)),
            ],
            'self-closed active tag with square brackets array argument with double-quoted key and single integer element in root context' => [
                '<f:c a="["foo":1]" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => 1])], $state, $context)),
            ],
            'self-closed active tag with square brackets array argument with sub-array element with quoted key and object accessor value in root context' => [
                '<f:c a="[foo:[baz:\'foo\']]" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => new ArrayNode(['baz' => 'foo'])])], $state, $context)),
            ],
            'self-closed active tag with square brackets array argument with sub-array element with escaped and quoted key and object accessor value in root context' => [
                '<f:c a="[foo:[baz:\\\'foo\\\']]" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => new ArrayNode(['baz' => 'foo'])])], $state, $context)),
            ],
            'self-closed active tag with square brackets array argument with multiple sub-array values in root context' => [
                '<f:c a="[["foo", "bar"], ["baz", "buzz"]]" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode([new ArrayNode(['foo', 'bar']), new ArrayNode(['baz', 'buzz'])])], $state, $context))
            ],
            'self-closed active tag with string argument with square bracket start not at first position in root context' => [
                '<f:c s="my [a:b]" />',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => 'my [a:b]'], $state, $context)),
            ],
            'self-closed active tag with value-less ECMA literal shorthand argument in root context' => [
                '<f:c s/>',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => new ObjectAccessorNode('s')], $state, $context)),
            ],
            'self-closed active tag with two value-less ECMA literal shorthand arguments in root context' => [
                '<f:c s i/>',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => new ObjectAccessorNode('s'), 'i' => new ObjectAccessorNode('i')], $state, $context)),
            ],
            'simple open + close active tag with value-less ECMA literal shorthand argument in root context' => [
                '<f:c s>x</f:c>',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => new ObjectAccessorNode('s')], $state, $context)->addChildNode(new TextNode('x'))),
            ],
            'simple open + close active tag with two value-less ECMA literal shorthand arguments in root context' => [
                '<f:c s i>x</f:c>',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['s' => new ObjectAccessorNode('s'), 'i' => new ObjectAccessorNode('i')], $state, $context)->addChildNode(new TextNode('x'))),
            ],

            /* INLINE */
            'simple inline in root context' => [
                '{foo}',
                $context,
                (new RootNode())->addChildNode(new ObjectAccessorNode('foo')),
            ],
            'accessor with dynamic part last in inline in root context' => [
                '{foo.{bar}}',
                $context,
                (new RootNode())->addChildNode(new ObjectAccessorNode('foo.{bar}')),
            ],
            'simple inline with text before in root context' => [
                'before {foo}',
                $context,
                (new RootNode())->addChildNode(new TextNode('before '))->addChildNode(new ObjectAccessorNode('foo')),
            ],
            'simple inline with text after in root context' => [
                '{foo} after',
                $context,
                (new RootNode())->addChildNode(new ObjectAccessorNode('foo'))->addChildNode(new TextNode(' after')),
            ],
            'simple inline with text before and after in root context' => [
                'before {foo} after',
                $context,
                (new RootNode())->addChildNode(new TextNode('before '))->addChildNode(new ObjectAccessorNode('foo'))->addChildNode(new TextNode(' after')),
            ],
            'escaped inline with text before and after in root context' => [
                'before \\{foo} after',
                $context,
                (new RootNode())->addChildNode(new TextNode('before {foo} after')),
            ],
            'simple inline ViewHelper without arguments in root context' => [
                '{f:c()}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse([], $state, $context)),
            ],
            'simple inline ViewHelper with single hardcoded integer argument in root context' => [
                '{f:c(i: 1)}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['i' => 1], $state, $context)),
            ],
            'simple inline ViewHelper with single hardcoded integer argument using tag attribute syntax in root context' => [
                '{f:c(i="1")}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['i' => 1], $state, $context)),
            ],
            'simple inline ViewHelper with single value-less hardcoded integer argument using tag attribute syntax in root context' => [
                '{f:c(i)}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['i' => new ObjectAccessorNode('i')], $state, $context)),
            ],
            'simple inline ViewHelper with two comma separated value-less hardcoded integer argument in root context' => [
                '{f:c(i, s)}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['i' => new ObjectAccessorNode('i'), 's' => new ObjectAccessorNode('s')], $state, $context)),
            ],
            'simple inline ViewHelper with two hardcoded arguments using tag attribute syntax without commas in root context' => [
                '{f:c(i="1" s="foo")}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['i' => 1, 's' => 'foo'], $state, $context)),
            ],
            'simple inline ViewHelper with two hardcoded arguments using tag attribute syntax with comma in root context' => [
                '{f:c(i="1", s="foo")}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['i' => 1, 's' => 'foo'], $state, $context)),
            ],
            'simple inline ViewHelper with two hardcoded arguments using tag attribute syntax with comma and tailing comma in root context' => [
                '{f:c(i="1", s="foo",)}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['i' => 1, 's' => 'foo'], $state, $context)),
            ],
            'simple inline ViewHelper with square brackets array argument using tag attribute syntax and array value using tag attribute syntax in root context' => [
                '{f:c(a="[foo="bar"]")}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => 'bar'])], $state, $context)),
            ],
            'simple inline ViewHelper with square brackets array argument with implied numeric keys with comma in root context' => [
                '{f:c(a="[foo, bar]")}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode([new ObjectAccessorNode('foo'), new ObjectAccessorNode('bar')])], $state, $context)),
            ],
            'simple inline ViewHelper with square brackets array argument with two items using implied numeric keys with quoted values using comma in root context' => [
                '{f:c(a="["foo", "bar"]")}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo', 'bar'])], $state, $context)),
            ],
            'simple inline ViewHelper with curly braces array argument with explicit numeric keys in root context' => [
                '{f:c(a="{0: foo, 1: bar}")}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode([new ObjectAccessorNode('foo'), new ObjectAccessorNode('bar')])], $state, $context)),
            ],
            'simple inline ViewHelper with curly braces array argument with redundant escapes in root context' => [
                '{f:c(a: {0: \\\'foo\\\'})}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo'])], $state, $context)),
            ],
            'simple inline ViewHelper with curly braces array argument with incorrect number of redundant escapes in root context' => [
                '{f:c(a: \'{0: \\\\\'{f:c(a: {foo})}\\\\\'}\')}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode([(new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => new ObjectAccessorNode('foo')])], $state, $context)])], $state, $context)),
            ],
            'simple inline ViewHelper with curly brackets array argument using tag attribute syntax and array value using tag attribute syntax in root context' => [
                '{f:c(a="{foo="bar"}")}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode(['foo' => 'bar'])], $state, $context)),
            ],
            'simple inline ViewHelper with value pipe in root context' => [
                '{string | f:c()}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse([], $state, $context)->addChildNode(new ObjectAccessorNode('string'))),
            ],
            /*
            'simple inline ViewHelper with multiple values pipe in root context' => [
                '{{string}{string}{string} | f:c()}',
                $context,
                (new RootNode())->addChildNode((new CViewHelper())->postParse([], $state)->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new ObjectAccessorNode('string'))->addChildNode(new ObjectAccessorNode('string'))),
            ],
            */
            'simple inline ViewHelper with value pipe to ViewHelper alias in root context' => [
                '{string | raw}',
                $context,
                (new RootNode())->addChildNode((new RawViewHelper())->postParse([], $state, $context)->addChildNode(new ObjectAccessorNode('string'))),
            ],

            /* INLINE PASS OF ARRAY */
            'inline ViewHelper with single-item quoted static key and value associative array in square brackets piped value in root context' => [
                '{["foo": "bar"] | f:format.raw()}',
                $context,
                (new RootNode())->addChildNode((new RawViewHelper())->addChildNode(new ArrayNode(['foo' => 'bar']))),
            ],
            'inline ViewHelper with single-item implied numeric index array in square brackets piped value in root context' => [
                '{["bar"] | f:format.raw()}',
                $context,
                (new RootNode())->addChildNode((new RawViewHelper())->addChildNode(new ArrayNode(['bar']))),
            ],
            'inline ViewHelper with multi-item implied numeric index array in square brackets piped value in root context' => [
                '{["foo", "bar", "baz"] | f:format.raw()}',
                $context,
                (new RootNode())->addChildNode((new RawViewHelper())->addChildNode(new ArrayNode(['foo', 'bar', 'baz']))),
            ],
            'inline ViewHelper with multi-item implied numeric index array with tailing comma in square brackets piped value in root context' => [
                '{["foo", "bar", "baz", ] | f:format.raw()}',
                $context,
                (new RootNode())->addChildNode((new RawViewHelper())->addChildNode(new ArrayNode(['foo', 'bar', 'baz']))),
            ],
            'inline ViewHelper with multi-item associative index array with space-separated key and value pairs in root context' => [
                '{[foo "foo", bar "bar"] | f:format.raw()}',
                $context,
                (new RootNode())->addChildNode((new RawViewHelper())->addChildNode(new ArrayNode(['foo' => 'foo', 'bar' => 'bar']))),
            ],

            /* PROTECTED INLINE */
            'inline syntax with explicitly escaped sub-syntax creates explicit accessor' => [
                '{foo.\{bar}}',
                $context,
                (new RootNode())->addChildNode(new ObjectAccessorNode('foo.{bar}')),
            ],
            'inline syntax with comma when array is not allowed is detected as possible expression that becomes TextNode' => [
                '{foo, bar, baz}',
                $context,
                (new RootNode())->addChildNode(new TextNode('{foo, bar, baz}')),
            ],
            'complex inline syntax not detected as Fluid in root context' => [
                '{string - with < raw || something}',
                $context,
                (new RootNode())->addChildNode(new TextNode('{string - with < raw || something}')),
            ],
            'complex inline syntax with sub-accessor-like syntax not detected as Fluid in root context' => [
                '{string with < raw {notFluid} || something}',
                $context,
                (new RootNode())->addChildNode(new TextNode('{string with < raw {notFluid} || something}')),
            ],
            'likely JS syntax not detected as Fluid in root context' => [
                '{"object": "something"}',
                $context,
                (new RootNode())->addChildNode(new TextNode('{"object": "something"}')),
            ],
            'likely CSS syntax not detected as Fluid in root context' => [
                'something { background-color: #000000; }',
                $context,
                (new RootNode())->addChildNode(new TextNode('something { background-color: #000000; }')),
            ],
            'likely JS syntax not detected as Fluid in inactive attribute value' => [
                '<tag prop="if(x.y[f.z].v){w.l.h=(this.o[this.s].v);}" />',
                $context,
                (new RootNode())->addChildNode(new TextNode('<tag prop="if(x.y[f.z].v){w.l.h=(this.o[this.s].v);}" />')),
            ],
            'empty inline renders as plaintext curly brace pair' => [
                '{}',
                $context,
                (new RootNode())->addChildNode(new TextNode('{}'))
            ],

            /* BACKTICK EXPLICIT VARIABLES */
            'backtick quotes in protected inline is detected as correct object accessor' => [
                'something { background-color: `{color}`; }',
                $context,
                (new RootNode())->addChildNode(new TextNode('something { background-color: '))->addChildNode(new ObjectAccessorNode('color'))->addChildNode(new TextNode('; }')),
            ],

            /* EXPRESSIONS */
            'inline that might be an expression node' => [
                '{inline something foo}',
                $context,
                (new RootNode())->addChildNode(new TextNode('{inline something foo}'))
            ],
            'inline that might be an expression node with parenthesis' => [
                '{inline (something) foo}',
                $context,
                (new RootNode())->addChildNode(new TextNode('{inline (something) foo}'))
            ],
            'inline math expression with spaces is detected as expression' => [
                '{i + 1}',
                $context,
                (new RootNode())->addChildNode(new MathExpressionNode('{i + 1}', ['{i + 1}', '{i + 1}']))
            ],
            'inline math expression without spaces is not detected as expression' => [
                '{i+1}',
                $context,
                (new RootNode())->addChildNode(new ObjectAccessorNode('i+1'))
            ],
            'casting expression is recognized as expression' => [
                '{i as string}',
                $context,
                (new RootNode())->addChildNode(new CastingExpressionNode('{i as string}', ['{i as string}', '{i as string}']))
            ],
            'expression error with tolerant error handler is created as TextNode' => [
                '{i as invalid}',
                $this->createContext(TolerantErrorHandler::class),
                (new RootNode())->addChildNode(new TextNode('Invalid expression: Invalid target conversion type "invalid" specified in casting expression "{i as invalid}".'))
            ],

            /* STRUCTURAL */
            'layout node with empty layout name' => [
                '<f:layout />',
                $context,
                (new RootNode())->addChildNode(new TextNode('')),
            ],
            'layout node with layout name' => [
                '<f:layout name="Default" />',
                $context,
                (new RootNode())->addChildNode(new TextNode('')),
            ],
            'section with name' => [
                '<f:section  name="Default">DefaultSection</f:section>',
                $context,
                (new RootNode())->addChildNode(new TextNode('')),
            ],
        ];
    }

    /**
     * @test
     */
    public function stressTestOneThousandArrayItems()
    {
        $context = $this->createContext();
        $state = $this->createState();
        $thousandRandomArrayItemsInline = '{f:c(a: {';
        $thousandRandomArray = [];
        $chars = str_split('abcdef1234567890');
        $createRandomString = function (int $length) use ($chars): string {
            $string = '';
            for ($i = 0; $i < $length; $i++) {
                $string .= array_rand($chars);
            }
            return $string;
        };
        for ($i = 0; $i < 1000; $i++) {
            $key = 'k' . $createRandomString(rand(16, 32));
            $value = 'v' . $createRandomString(rand(16, 32));
            $thousandRandomArrayItemsInline .= $key . ': "' . $value . '", ';
            $thousandRandomArray[$key] = $value;
        }
        $thousandRandomArrayItemsInline .= '})}';
        $expectedRootNode = (new RootNode())->addChildNode((new CViewHelper())->postParse(['a' => new ArrayNode($thousandRandomArray)], $state, $context));
        $this->createsExpectedNodeStructure($thousandRandomArrayItemsInline, $context, $expectedRootNode);
    }

    /**
     * @test
     */
    public function stressTestOneHundredInlinePasses()
    {
        $context = $this->createContext();
        $template = '{foo ';

        $expectedRootNode = new RootNode();
        $node = $expectedRootNode;
        for ($i = 0; $i < 100; $i++) {
            $childNode = new RawViewHelper();
            $node->addChildNode($childNode);
            $template .= '| f:format.raw() ';
            $node = $childNode;
        }
        $node->addChildNode(new ObjectAccessorNode('foo'));
        $template .= '}';

        $this->createsExpectedNodeStructure($template, $context, $expectedRootNode);
    }

    /**
     * @test
     * @dataProvider getEscapingTestValues
     * @param string $template
     * @param RenderingContextInterface $context
     * @param RootNode $expectedRootNode
     */
    public function escapingTest(string $template, RenderingContextInterface $context, RootNode $expectedRootNode)
    {
        $this->createsExpectedNodeStructure($template, $context, $expectedRootNode);
    }

    public function getEscapingTestValues(): array
    {
        $context = $this->createContext();
        $context->getTemplateParser()->getConfiguration()->addEscapingInterceptor(new Escape());
        return [
            'escapes object accessors in root context' => [
                '{foo}',
                $context,
                (new RootNode())->addChildNode((new EscapingNode(new ObjectAccessorNode('foo')))),
            ],
        ];
    }

    protected function createContext(string $errorHandlerClass = StandardErrorHandler::class): RenderingContextInterface
    {
        $variableProvider = $this->getMockBuilder(VariableProviderInterface::class)->getMock();
        $variableProvider->expects($this->any())->method('get')->with('s')->willReturn('I am a shorthand string');
        $variableProvider->expects($this->any())->method('get')->with('i')->willReturn(321);
        $variableProvider->expects($this->any())->method('get')->with('string')->willReturn('I am a string');
        $variableProvider->expects($this->any())->method('get')->with('integer')->willReturn(42);
        $variableProvider->expects($this->any())->method('get')->with('numericArray')->willReturn(['foo', 'bar']);
        $variableProvider->expects($this->any())->method('get')->with('associativeArray')->willReturn(['foo' => 'bar']);
        $variableProvider->expects($this->any())->method('getScopeCopy')->willReturnSelf();
        $viewHelperResolver = new ViewHelperResolver();
        $errorHandler = new $errorHandlerClass();
        $viewHelperResolver->addNamespace('f', 'TYPO3Fluid\\Fluid\\Tests\\Unit\\Core\\Parser\\Fixtures\\ViewHelpers');
        $viewHelperResolver->addViewHelperAlias('raw', 'f', 'format.raw');
        $parserConfiguration = new Configuration();
        $parserConfiguration->setFeatureState(Configuration::FEATURE_SEQUENCER, true);
        $context = $this->getMockBuilder(RenderingContextInterface::class)->getMock();
        $templateParser = $this->getMockBuilder(TemplateParser::class, ['getConfiguration'])->getMock();
        $templateParser->expects($this->any())->method('getConfiguration')->willReturn($parserConfiguration);
        $templateParser->setRenderingContext($context);
        $context->setViewHelperResolver($viewHelperResolver);
        $context->expects($this->any())->method('buildParserConfiguration')->willReturn($parserConfiguration);
        $context->expects($this->any())->method('getTemplateParser')->willReturn($templateParser);
        $context->expects($this->any())->method('getViewHelperResolver')->willReturn($viewHelperResolver);
        $context->expects($this->any())->method('getVariableProvider')->willReturn($variableProvider);
        $context->expects($this->any())->method('getErrorHandler')->willReturn($errorHandler);
        $context->expects($this->any())->method('getExpressionNodeTypes')->willReturn([MathExpressionNode::class, CastingExpressionNode::class]);
        $context->expects($this->any())->method('getTemplateProcessors')->willReturn([]);
        return $context;
    }

    protected function createState(): ParsingState
    {
        $state = new ParsingState();
        return $state;
    }

    protected function assertNodeEquals(NodeInterface $subject, NodeInterface $expected, string $path = '')
    {
        $this->assertInstanceOf(get_class($expected), $subject, 'Node types not as expected at path: ' . $path);
        if ($subject instanceof ViewHelperInterface) {
            $expectedArguments = $expected->getParsedArguments();
            $passedArguments = $subject->getParsedArguments();
            foreach ($passedArguments as $name => $argument) {
                if (isset($expectedArguments[$name])) {
                    if ($argument instanceof NodeInterface && $expectedArguments[$name] instanceof NodeInterface) {
                        $this->assertNodeEquals($argument, $expectedArguments[$name], $path . '.argument@' . $name);
                    } else {
                        $this->assertSame($expectedArguments[$name], $argument, 'Arguments at path ' . $path . '.argument@' . $name . ' did not match');
                    }
                }
                unset($expectedArguments[$name]);
            }
            if (!empty($expectedArguments)) {
                $this->fail('ViewHelper did not receive expected arguments: ' . var_export($expectedArguments, true) . ' vs received ' . var_export($passedArguments, true));
            }
        } elseif ($subject instanceof ObjectAccessorNode) {
            $this->assertSame($expected->getObjectPath(), $subject->getObjectPath(), 'ObjectAccessors do not match at path ' . $path);
        } elseif ($subject instanceof TextNode) {
            $this->assertSame($expected->getText(), $subject->getText(), 'TextNodes do not match at path ' . $path);
        } elseif ($subject instanceof ArrayNode) {
            $this->assertEquals($expected->getInternalArray(), $subject->getInternalArray(), 'Arrays do not match at path ' . $path);
        } elseif ($subject instanceof ExpressionNodeInterface) {
            $this->assertEquals($expected->getMatches(), $subject->getMatches(), 'Expression matches are not equal at path ' . $path);
        }

        $children = $subject->getChildNodes();
        $expectedChildren = $expected->getChildNodes();
        if (count($children) !== count($expectedChildren)) {
            $this->fail('Nodes have an unequal number of child nodes. Got ' . count($children) . ', expected ' . count($expectedChildren) . '. Path: ' . $path);
        }
        foreach ($expectedChildren as $index => $expectedChild) {
            $child = $children[$index];
            $this->assertNodeEquals($child, $expectedChild, get_class($subject) . '.child@' . $index);
        }
    }
}