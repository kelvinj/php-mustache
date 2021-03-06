<?php
/**
 * @package php-mustache
 * @subpackage auxillary
 * @author Ingmar Runge 2012 - https://github.com/KiNgMaR - BSD license
 **/


/**
 *
 * @package php-mustache
 * @subpackage auxillary
 **/
class MustacheJavaScriptCodeGen
{
	/**
	 * Root section from MustacheParser's getTree().
	 * @var MustacheParserSection
	 **/
	protected $tree = NULL;
	/**
	 * @var int
	 **/
	protected $whitespace_mode;
	/**
	 * @var bool
	 **/
	protected $compact_literals;

	/**
	 * Used to keep track of recursive partials during compilation.
	 * @var array
	 **/
	private $registered_partials = array();

	/**
	 * Note 1: for successful JS code generation, the template must be provided in UTF-8 encoding!
	 * Note 2: while $compact_literals reduces the output code size, it must not be used when deploying
	 * the generated code inside an HTML document as e.g. </script> is no longer being escaped. Do
	 * not use if you don't fully understand the implications.
	 * @param MustacheParser $parser Parser with the syntax tree.
	 * @param bool $compact_literals
	 **/
	public function __construct(MustacheParser $parser, $compact_literals = false)
	{
		$this->tree = $parser->getTree();
		$this->whitespace_mode = $parser->getWhitespaceMode();
		$this->compact_literals = $compact_literals;
	}

	/**
	 * Returns the runtime library JS code that is required when executing the
	 * function(){...} code returned by generate. Feel free to minimize the
	 * returned JS blob before deploying.
	 * @see generate
	 * @return string
	 **/
	public static function getRuntimeCode()
	{
		return <<<'EOJS'

/**
 * Runtime class for mustache templates 'compiled' by php-mustache.
 * Feel free to minimize this when deploying.
 *
 * Using one-letter method names to help keep the generated code small.
 **/
(function() {

	// static data:
	var _charsToEscape = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;'
	};

	// XML escaping helper:
	function xmlEscape(str)
	{
		str = new String(!str && str !== 0 ? '' : str);
		// fastest method according to http://jsperf.com/encode-html-entities
		return str.replace(/[&<>"]/g, function(ch) { return _charsToEscape[ch]; });
	};

	// is_array helper function:
	var is_array = Array.isArray || function(a) {
		return Object.prototype.toString.call(a) === '[object Array]';
	};

	// lookup business internal static functions:
	function look_up_var_flat(stack, var_name)
	{
		// walk stack from top to bottom:
		for(var i = stack.length - 1; i >= 0; i--)
		{
			var item = look_up_var_in_context(stack[i], var_name);

			if(item !== null)
			{
				return item;
			}
		}

		return '';
	}

	function look_up_var_in_context(ctx, var_name)
	{
		if(typeof ctx === 'object' && typeof ctx[var_name] !== 'undefined')
		{
			// :TODO: consider adding support for callable members
			return ctx[var_name];
		}

		return null;
	}

	function is_section_falsey(secv)
	{
		if(secv === undefined || secv === null || secv === 0 || secv === false || secv === '')
			return true;
		else if(is_array(secv) && secv.length === 0)
			return true;

		return !secv;
	}

	// main runtime class:
	MustacheRuntime = function(data)
	{
		this.partials = { }; // used for recursive partials only
		this.stack = [ data ];
		// using an array to avoid memory reallocations as the buffer grows:
		this.buf = [];
	};

	MustacheRuntime.prototype = {
		// literal
		l: function(s)
		{
			this.buf.push(s);
		},

		// variable
		v: function(var_name, no_esc)
		{
			var val = this._look_up_var(var_name);
			if(no_esc !== 1)
				this.buf.push(xmlEscape(val));
			else
				this.buf.push(val);
		},

		// section:
		s: function(is_inverted, var_name, core)
		{
			var secv = this._look_up_var(var_name);

			var falsey = is_section_falsey(secv);

			if(is_inverted === 1 || falsey)
			{
				if(is_inverted === 1 && falsey)
				{
					this.stack.push(secv);
					core();
					this.stack.pop();
				}

				return;
			}

			// it's a regular section.

			if(!is_array(secv))
			{
				// wrap scalars and objects...
				secv = [ secv ];
			}

			for(var i in secv)
			{
				this.stack.push(secv[i]);
				core();
				this.stack.pop();
			}
		},

		// register partial (used for recursive partials only):
		pr: function(name, func)
		{
			this.partials[name] = func;
		},

		// run partial:
		p: function(name)
		{
			this.partials[name].call();
		},

		_look_up_var: function(var_name)
		{
			var item = null;

			if(var_name === '.')
			{
				item = this.stack[this.stack.length - 1];
			}
			else
			{
				if(is_array(var_name)) // is this dot syntax?
				{
					item = look_up_var_flat(this.stack, var_name.shift());

					while(var_name.length > 0 && !!item)
					{
						item = look_up_var_in_context(item, var_name.shift());
					}
				}
				else
				{
					item = look_up_var_flat(this.stack, var_name);
				}
			}

			return item;
		},

		_buffer: function(s)
		{
			this.buf.push(s);
		},

		get: function()
		{
			return this.buf.join('');
		},
	};

})(); // end of wrapper to keep scope private

EOJS;
	}

	/**
	 * Returns JavaScript code that yields equal results as the provided template.
	 * Its structure looks like "function(data){...}" where data is the data variable
	 * that is to be used while executing the template. The returned code can only
	 * run successfully if the library provided by getRuntimeCode() is present.
	 * @see getRuntimeCode
	 * @return string
	 **/
	public function generate()
	{
		// arguments:
		// - context (that is data)
		$js = 'function(_c){var r=new MustacheRuntime(_c);';

		// start code generation:
		$js .= $this->generateInternal($this->tree);

		$js .= 'return r.get()}';

		return $js;
	}

	/**
	 * Same as generate(), but returns a function that optionally takes a second argument
	 * which is a string describing a root section that the code descends into before executing
	 * the template. Useful when executing partials separately.
	 * @see generate
	 * @return string
	 **/
	public function generateEx()
	{
		$js = 'function(_c,_s){var r=new MustacheRuntime(_c),_f=function(){';

		$js .= $this->generateInternal($this->tree);

		$js .= '};if(_s){r.s(0,_s,_f)}else{_f()}';
		$js .= 'return r.get()}';

		return $js;
	}

	/**
	 * Escapes whitespace and friends, then returns the given string with quotes around it so it can be used in JS.
	 * @param string $str
	 * @return string
	 **/
	protected function quoteLiteral($str)
	{
		if(!$this->compact_literals)
		{
			return json_encode($str);
		}
		else
		{
			// Add less backslashes by using single quotes
			// (HTML usually has double quotes => less to escape)
			// and escaping less characters (leave UTF-8 as it is)...
			// ...thereby achieving a smaller JS code blob.
			return '\'' . strtr(str_replace("\r\n", "\n", $str), array(
					'\\' => '\\\\',
					"\0" => '\0',
					"\r" => '\r',
					"\n" => '\n',
					'\'' => '\\\'',
				)) . '\'';
		}
	}

	/**
	 * Branches into the appropriate generating method based on $obj's class.
	 * @param MustacheParserObject $obj child class instance inheriting from MustacheParserObject
	 * @return string
	 **/
	protected function generateInternal(MustacheParserObject $obj)
	{
		if($obj instanceof MustacheParserSection)
		{
			return $this->generateSection($obj);
		}
		elseif($obj instanceof MustacheParserLiteral)
		{
			$str = $obj->getContents();

			if($this->whitespace_mode == MUSTACHE_WHITESPACE_STRIP)
			{
				$str = preg_replace('~\s+~', ' ', $str);
			}

			return 'r.l(' . $this->quoteLiteral($str) . ');';
		}
		elseif($obj instanceof MustacheParserVariable)
		{
			return $this->generateVar($obj);
		}
		elseif($obj instanceof MustacheParserRuntimeTemplate)
		{
			return $this->generateRuntimeTemplate($obj);
		}
	}

	/**
	 * Returns an intermediate JS code representation of $var - important for dot syntax.
	 * @param MustacheParserObjectWithName $var
	 * @return string
	 **/
	protected function varToJs(MustacheParserObjectWithName $var)
	{
		if($var->isDotNotation())
			return json_encode($var->getNames());
		else
			return $this->quoteLiteral($var->getName());
	}

	/**
	 * Generates JS code that runs a section.
	 * @param $MustacheParserSection $section
	 * @return string
	 **/
	protected function generateSection(MustacheParserSection $section)
	{
		$is_root = ($section->getName() === '#ROOT#');
		$inverted_section = ($section instanceof MustacheParserInvertedSection);

		$s = '';

		if(!$is_root)
		{
			$s .= 'r.s(' . ($inverted_section ? '1' : '0') . ',' . $this->varToJs($section) .
				',function(){';
		}

		foreach($section as $child)
		{
			$s .= $this->generateInternal($child);
		}

		if(!$is_root)
		{
			$s .= '});';
		}

		return $s;
	}

	/**
	 * Generates JS code that looks up and inserts variable contents.
	 * @param MustacheParserVariable $var
	 * @return string
	 **/
	protected function generateVar(MustacheParserVariable $var)
	{
		return 'r.v(' . $this->varToJs($var) . ($var->escape() ? '' : ',1') . ');';
	}

	/**
	 *
	 * @param MustacheParserRuntimeTemplate $tpl
	 * @return string
	 **/
	protected function generateRuntimeTemplate(MustacheParserRuntimeTemplate $tpl)
	{
		$s = '';

		if(!isset($this->registered_partials[$tpl->getName()]))
		{
			// this only works correctly because the partial has been expanded
			// once by the parser - this an inner call of the recursion!
			$this->registered_partials[$tpl->getName()] = true;

			$parser = new MustacheParser($tpl->lookupSelf(), $this->whitespace_mode);
			$parser->addPartials($tpl->getPartials());
			$parser->parse();

			$child = new self($parser, $this->compact_literals);
			$child->registered_partials = &$this->registered_partials;
			// register partial in JS so it's known by name:
			$s .= 'r.pr(' . $this->quoteLiteral($tpl->getName()) . ',function(){' .
				$child->generateInternal($child->tree) . '});';
		}

		$s .= 'r.p(' . $this->quoteLiteral($tpl->getName()) . ');';

		return $s;
	}
}


/**
 * Pull in parser classes...
 **/
require_once dirname(__FILE__) . '/MustacheParser.php';
