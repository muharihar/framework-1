<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\View\Processors\Templater;

use Spiral\Core\Component;
use Spiral\Support\Html\Tokenizer;

class Node
{
    /**
     * Tagging behaviour types. HTML node templater supports 3 basic behaviours: extend, import, and
     * block definition. By combining these behaviours, you can build almost any template.
     */
    const TYPE_BLOCK  = 20;
    const TYPE_EXTEND = 21;
    const TYPE_IMPORT = 22;

    /**
     * While importing, this block will contain the entire node import.
     */
    const CONTEXT_BLOCK = 'context';

    /**
     * Short tags expression.
     */
    const SHORT_TAGS = '/\${(?P<name>[a-z0-9_\.\-]+)(?: *\| *(?P<default>[^}]+) *)?}/i';

    /**
     * Content and behaviour supervisor will load and any tag definitions will be passed through it.
     * Generally, Supervisor is used to perform high level template management.
     *
     * @var SupervisorInterface
     */
    protected $supervisor = null;

    /**
     * Node name is used for rendering and reference purposes. The top node in the chain must be named
     * "root".
     *
     * @var string
     */
    protected $name = '';

    /**
     * Additional node options can be set within supervisor to describe the nodes behaviour.
     *
     * @var array
     */
    public $options = [];

    /**
     * Set of child nodes being used during rendering.
     *
     * @var string[]|Node[]
     */
    protected $nodes = [];

    /**
     * Nodes being parsed but not used in rendering.
     *
     * @var string[]|Node[]
     */
    protected $skippedNodes = [];

    /**
     * Parent node to be extended after processing the current view.
     *
     * @var Node
     */
    public $parent = null;

    /**
     * This will create a new HTML Node object. Every node should be named for reference purposes.
     * Call your (top) node "root". If you provide a list of previously parsed HTML tokens, this can
     * speed up the processing if there are multiple, identical imports.
     *
     * @param SupervisorInterface $supervisor Content loaded and behaviour resolver.
     * @param string              $name       Node name. Use "root" for top node.
     * @param string|array        $source     Node source or array of parsed tokens.
     * @param array               $options    Options for Node behaviour.
     */
    public function __construct(
        SupervisorInterface $supervisor,
        $name = 'root',
        $source = [],
        $options = []
    )
    {
        $this->supervisor = $supervisor;
        $this->name = $name;
        $this->options = $options;

        if (!empty($source))
        {
            if (is_array($source))
            {
                $this->parseTokens($source);
            }
            else
            {
                $this->parseSource($source);
            }
        }

        if (!empty($this->parent))
        {
            $this->extendParent($this->parent);
            $this->parent = null;
        }
    }

    /**
     * Block nesting level.
     *
     * @return int
     */
    public function getLevel()
    {
        if (empty($this->parent))
        {
            return 0;
        }

        return $this->parent->getLevel() + 1;
    }

    /**
     * Parse text source.
     *
     * @param string $source
     */
    protected function parseSource($source)
    {
        $this->parseTokens(Tokenizer::parseSource($source));
    }

    /**
     * Parse provided tokens. Set of tokens should valid output from html\Tokenizer.
     *
     * @param array $tokens
     */
    protected function parseTokens(array $tokens)
    {
        //Current token behaviour (what it is: import, extend or block definition)
        $behaviour = null;

        //Current active token
        $current = [];

        //Content to represent full tag declaration (including body)
        $content = [];

        //Some blocks can be named as parent. We have to make sure we closing the correct one
        $tokenLevel = 0;
        foreach ($tokens as $token)
        {
            $tokenType = $token[Tokenizer::TOKEN_TYPE];

            if (empty($current))
            {
                if ($tokenType == Tokenizer::TAG_OPEN || $tokenType == Tokenizer::TAG_SHORT)
                {
                    $behaviour = $this->describeToken($token, $this);

                    if (empty($behaviour))
                    {
                        //Token should be skipped
                        continue;
                    }

                    if ($behaviour instanceof Behaviour)
                    {
                        if ($tokenType == Tokenizer::TAG_SHORT)
                        {
                            $this->registerNode($behaviour, []);
                            continue;
                        }

                        $current = $token;
                        continue;
                    }
                }

                if ($tokenType == Tokenizer::TAG_CLOSE)
                {
                    $this->describeToken($token, $this);
                }

                //Looking for short tag definitions
                if (preg_match_all(self::SHORT_TAGS, $token[Tokenizer::TOKEN_CONTENT], $matches))
                {
                    foreach ($matches['name'] as $index => $name)
                    {
                        $chunks = explode($matches[0][$index], $token[Tokenizer::TOKEN_CONTENT]);
                        $this->nodes[] = array_shift($chunks);

                        $node = new static($this->supervisor, $name, [], $this->options);
                        $node->nodes = [$matches['default'][$index]];
                        $this->nodes[] = $node;

                        $token[Tokenizer::TOKEN_CONTENT] = join($matches[0][$index], $chunks);
                    }
                }

                //Not a node and can be represented as simple string
                if (is_string(end($this->nodes)))
                {
                    $this->nodes[key($this->nodes)] .= $token[Tokenizer::TOKEN_CONTENT];
                }
                else
                {
                    $this->nodes[] = $token[Tokenizer::TOKEN_CONTENT];
                }

                continue;
            }

            if ($tokenType == Tokenizer::TAG_OPEN || $tokenType == Tokenizer::TAG_SHORT)
            {
                if (!$this->describeToken($token, $this))
                {
                    //Just skipping
                    continue;
                }

                //There is a block with the same name as parent one, we have to make sure we are
                //closing correct block
                if ($token[Tokenizer::TOKEN_TYPE] == Tokenizer::TAG_OPEN)
                {
                    if ($token[Tokenizer::TOKEN_NAME] == $current[Tokenizer::TOKEN_NAME])
                    {
                        $content[] = $token;
                        $tokenLevel++;
                        continue;
                    }
                }
            }

            if ($tokenType == Tokenizer::TAG_CLOSE)
            {
                if (!$this->describeToken($token, $this))
                {
                    //Just skipping
                    continue;
                }

                if ($behaviour && $behaviour instanceof Behaviour)
                {
                    if ($token[Tokenizer::TOKEN_NAME] == $current[Tokenizer::TOKEN_NAME])
                    {
                        if ($tokenLevel === 0)
                        {
                            //Closing current token
                            $this->registerNode($behaviour, $content);

                            $current = [];
                            $content = [];
                        }
                        else
                        {
                            $content[] = $token;
                            $tokenLevel--;
                        }

                        continue;
                    }
                }
            }

            $content[] = $token;
        }
    }

    /**
     * Register a new node based on the behaviours definition and content.
     *
     * @param Behaviour $behaviour
     * @param array     $content
     */
    protected function registerNode(Behaviour $behaviour, array $content)
    {
        switch ($behaviour->type)
        {
            case self::TYPE_EXTEND:
                $this->parent = $behaviour->contextNode;

                foreach ($behaviour->attributes as $attribute => $value)
                {
                    if ($value instanceof Behaviour)
                    {
                        $node = new static($this->supervisor, $attribute, [], $value->options);
                    }
                    else
                    {
                        $node = new static($this->supervisor, $attribute, [], $this->options);
                        $node->nodes = [$value];
                    }

                    $this->nodes[] = $node;
                }
                break;

            case self::TYPE_BLOCK:

                //Registering new block node
                $this->nodes[] = new static(
                    $this->supervisor,
                    $behaviour->name,
                    $content,
                    $behaviour->options
                );

                break;

            case self::TYPE_IMPORT:

                //Attributes will be used as nodes too
                foreach ($behaviour->attributes as $attribute => $value)
                {
                    $node = new static($this->supervisor, $attribute);
                    $node->nodes = [$value];

                    $behaviour->contextNode->nodes[] = $node;
                }

                /**
                 * We are putting all the children nodes into a context node, so you can use this
                 * construction as:
                 *
                 * <tag foo="bar">my data without block tags</tag>
                 *
                 * Context will be in the same namespace as the parents.
                 */
                $behaviour->contextNode->nodes[] = new static(
                    $this->supervisor,
                    self::CONTEXT_BLOCK,
                    $content,
                    $behaviour->options
                );

                $this->nodes[] = $behaviour->contextNode;
                if (!empty($behaviour->contextNode->parent))
                {
                    $behaviour->contextNode->extendParent($behaviour->contextNode->parent);
                }

                break;
        }
    }

    /**
     * All children nodes (aliased with their names).
     *
     * @return Node[]
     */
    public function getNodes()
    {
        $result = [];
        foreach ($this->nodes as $node)
        {
            if ($node instanceof Node)
            {
                $result[$node->name] = $node;
                if ($node->name == self::CONTEXT_BLOCK)
                {
                    $result = array_merge($result, $node->getNodes());
                }
            }
        }

        return $result;
    }

    /**
     * Find a children node by name.
     *
     * @param string $target
     * @return Node|null
     */
    public function findNode($target)
    {
        foreach ($this->nodes as $node)
        {
            if ($node instanceof self && $node->name)
            {
                if ($node->name === $target)
                {
                    return $node;
                }

                if ($found = $node->findNode($target))
                {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Extending parent node based on the provided behaviour and source.
     *
     * @param Node $parent
     */
    public function extendParent(Node $parent)
    {
        foreach ($this->getNodes() as $name => $node)
        {
            if ($target = $parent->findNode($name))
            {
                //Self including
                if ($super = $node->findNode($name))
                {
                    $super->nodes = $target->nodes;
                }

                $target->nodes = $node->nodes;

                //Applying options but not recursively
                $target->options = $node->options;
            }
            else
            {
                $this->skippedNodes[] = $node;
            }
        }

        $this->nodes = $parent->nodes;
        unset($parent);
    }

    /**
     * Will compile all existing nodes. Compiled block will replace itself in future occurrences.
     *
     * @param Node  $parent   Parent node.
     * @param array $compiled Nodes already compiled (in case of aliasing).
     * @return string
     */
    public function compile(Node $parent = null, &$compiled = [])
    {
        $result = '';
        foreach ($this->nodes as $node)
        {
            if ($node instanceof Node)
            {
                if (!$node->name)
                {
                    //todo: add ability to ingest parent block into included view, use node options
                    $result .= $node->compile($this);
                    continue;
                }

                if (!array_key_exists($node->name, $compiled))
                {
                    //Node was never compiled
                    $compiled[$node->name] = $node->compile($this, $compiled);
                }

                $result .= $compiled[$node->name];
            }
            else
            {
                $result .= $node;
            }
        }

        return $this->compileDynamicNodes($result);
    }

    /**
     * Mount dynamically created attributes.
     *
     * @param string $result
     * @return string
     */
    protected function compileDynamicNodes($result)
    {
        if (preg_match_all(
            '/ node:attributes(=[\'"]'
            . '(?:include:(?P<include>[a-z_\-,]+))?\|?'
            . '(?:exclude:(?P<exclude>[a-z_\-,]+))?[\'"])?/i',
            $result,
            $matches
        ))
        {
            foreach ($matches[0] as $id => $replace)
            {
                $include = $matches['include'][$id] ? explode(',', $matches['include'][$id]) : [];
                $exclude = $matches['exclude'][$id] ? explode(',', $matches['exclude'][$id]) : [];

                $dynamicNodes = [];
                foreach ($this->skippedNodes as $node)
                {
                    if (
                        !$node->name
                        || in_array($node->name, $exclude)
                        || ($include && !in_array($node->name, $include))
                    )
                    {
                        continue;
                    }

                    if (!empty($node->name))
                    {
                        $dynamicNodes[$node->name] = $node->compile();
                    }
                }

                unset($dynamicNodes[self::CONTEXT_BLOCK]);

                //Rendering (yes, we can render this part during collecting, 5 lines to top), but i
                //want to do it like this, cos it will be more flexible to add more features in future
                foreach ($dynamicNodes as $name => $attribute)
                {
                    $dynamicNodes[$name] = $name . '="' . $attribute . '"';
                }

                $result = str_replace(
                    $replace,
                    $dynamicNodes ? ' ' . join(' ', $dynamicNodes) : '',
                    $result
                );
            }
        }

        return $result;
    }

    /**
     * Use supervisor to detect token behaviour (smart token definition).
     *
     * @param array $token   Valid html\Tokenizer token.
     * @param Node  $current Currently active node.
     * @return mixed|Behaviour
     */
    protected function describeToken(&$token, Node $current)
    {
        return $this->supervisor->describeToken($token, $current);
    }
}