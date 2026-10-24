<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * LaTeX math as terminal-friendly Unicode text — upstream's `latex.ts`, ported line for line.
 *
 * `render()` is upstream's `renderLatex()`: `null` (upstream's `undefined`) when the expression
 * holds unsupported or malformed syntax, so the caller can show the source as written. Display
 * mode stacks fractions and operator limits and lays scripts out on lines of their own.
 *
 * Where JavaScript and PHP differ, the port takes JavaScript's side:
 *
 * - **Characters.** The parser walks code points (`$chars`), not bytes, so a one-character
 *   argument such as `^é` is the whole character, as it is to upstream for anything in the BMP.
 * - **Whitespace.** JavaScript's `\s` and `trim()` know the Unicode spaces and PHP's `trim()` does
 *   not (and strips `\0`, which upstream uses as a marker), so every `\s` and trim here goes
 *   through `WS`, JavaScript's own whitespace set.
 * - **Anchors and digits.** `$` in PCRE also matches before a final newline and `\d` with `u`
 *   matches every script's digits, so patterns use `\z`-strict `D` and `[0-9]`.
 *
 * The layout markers are private-use code points, as upstream's are; they never reach the output.
 */
final class Latex
{
    /** JavaScript's `\s`: the characters `String.prototype.trim()` removes. */
    private const string WS = '\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private const string NEGATIVE_SPACE = "\0";

    private const string NAMED_OPERATOR_START = "\u{f0004}";

    private const string NAMED_OPERATOR_END = "\u{f0005}";

    private const string LAYOUT_MARKER_START = "\u{f0000}";

    private const string LAYOUT_MARKER_END = "\u{f0001}";

    private const string PROTECTED_SPACE = "\u{f0002}";

    private const array MATRIX_DELIMITERS = [
        'pmatrix' => ['⎛', '⎞', '⎜', '⎟', '⎝', '⎠'],
        'bmatrix' => ['⎡', '⎤', '⎢', '⎥', '⎣', '⎦'],
        'Bmatrix' => ['⎧', '⎫', '⎨', '⎬', '⎩', '⎭'],
        'vmatrix' => ['│', '│', '│', '│', '│', '│'],
        'Vmatrix' => ['║', '║', '║', '║', '║', '║'],
    ];

    private const array SYMBOLS = [
        'alpha' => 'α',
        'beta' => 'β',
        'gamma' => 'γ',
        'delta' => 'δ',
        'epsilon' => 'ϵ',
        'varepsilon' => 'ε',
        'zeta' => 'ζ',
        'eta' => 'η',
        'theta' => 'θ',
        'vartheta' => 'ϑ',
        'iota' => 'ι',
        'kappa' => 'κ',
        'varkappa' => 'ϰ',
        'lambda' => 'λ',
        'mu' => 'μ',
        'nu' => 'ν',
        'xi' => 'ξ',
        'pi' => 'π',
        'varpi' => 'ϖ',
        'rho' => 'ρ',
        'varrho' => 'ϱ',
        'sigma' => 'σ',
        'varsigma' => 'ς',
        'tau' => 'τ',
        'upsilon' => 'υ',
        'phi' => 'ϕ',
        'varphi' => 'φ',
        'chi' => 'χ',
        'psi' => 'ψ',
        'omega' => 'ω',
        'Gamma' => 'Γ',
        'Delta' => 'Δ',
        'Theta' => 'Θ',
        'Lambda' => 'Λ',
        'Xi' => 'Ξ',
        'Pi' => 'Π',
        'Sigma' => 'Σ',
        'Upsilon' => 'Υ',
        'Phi' => 'Φ',
        'Psi' => 'Ψ',
        'Omega' => 'Ω',
        'pm' => '±',
        'mp' => '∓',
        'times' => '×',
        'div' => '÷',
        'cdot' => '·',
        'ast' => '∗',
        'star' => '⋆',
        'circ' => '∘',
        'bullet' => '•',
        'oplus' => '⊕',
        'ominus' => '⊖',
        'otimes' => '⊗',
        'oslash' => '⊘',
        'odot' => '⊙',
        'bigcirc' => '○',
        'dagger' => '†',
        'ddagger' => '‡',
        'amalg' => '⨿',
        'uplus' => '⊎',
        'sqcap' => '⊓',
        'sqcup' => '⊔',
        'bowtie' => '⋈',
        'Join' => '⋈',
        'ltimes' => '⋉',
        'rtimes' => '⋊',
        'leftouterjoin' => '⟕',
        'rightouterjoin' => '⟖',
        'fullouterjoin' => '⟗',
        'triangleleft' => '◁',
        'triangleright' => '▷',
        'wr' => '≀',
        'cap' => '∩',
        'cup' => '∪',
        'bigcap' => '⋂',
        'bigcup' => '⋃',
        'bigwedge' => '⋀',
        'bigvee' => '⋁',
        'bigsqcup' => '⨆',
        'biguplus' => '⨄',
        'bigoplus' => '⨁',
        'bigotimes' => '⨂',
        'bigodot' => '⨀',
        'setminus' => '∖',
        'in' => '∈',
        'notin' => '∉',
        'ni' => '∋',
        'subset' => '⊂',
        'supset' => '⊃',
        'subseteq' => '⊆',
        'supseteq' => '⊇',
        'sqsubset' => '⊏',
        'sqsupset' => '⊐',
        'sqsubseteq' => '⊑',
        'sqsupseteq' => '⊒',
        'prec' => '≺',
        'preceq' => '≼',
        'succ' => '≻',
        'succeq' => '≽',
        'll' => '≪',
        'gg' => '≫',
        'le' => '≤',
        'leq' => '≤',
        'leqslant' => '≤',
        'ge' => '≥',
        'geq' => '≥',
        'geqslant' => '≥',
        'ne' => '≠',
        'neq' => '≠',
        'equiv' => '≡',
        'approx' => '≈',
        'sim' => '∼',
        'simeq' => '≃',
        'cong' => '≅',
        'asymp' => '≍',
        'doteq' => '≐',
        'propto' => '∝',
        'parallel' => '∥',
        'perp' => '⊥',
        'mid' => '∣',
        'vdash' => '⊢',
        'dashv' => '⊣',
        'models' => '⊨',
        'Vdash' => '⊩',
        'Vvdash' => '⊪',
        'nvdash' => '⊬',
        'nvDash' => '⊭',
        'forall' => '∀',
        'exists' => '∃',
        'nexists' => '∄',
        'neg' => '¬',
        'land' => '∧',
        'wedge' => '∧',
        'lor' => '∨',
        'vee' => '∨',
        'to' => '→',
        'rightarrow' => '→',
        'longrightarrow' => '→',
        'leftarrow' => '←',
        'longleftarrow' => '←',
        'gets' => '←',
        'leftrightarrow' => '↔',
        'longleftrightarrow' => '↔',
        'hookleftarrow' => '↩',
        'hookrightarrow' => '↪',
        'twoheadleftarrow' => '↞',
        'twoheadrightarrow' => '↠',
        'leftharpoonup' => '↼',
        'leftharpoondown' => '↽',
        'rightharpoonup' => '⇀',
        'rightharpoondown' => '⇁',
        'rightleftharpoons' => '⇌',
        'leftrightharpoons' => '⇋',
        'nearrow' => '↗',
        'searrow' => '↘',
        'swarrow' => '↙',
        'nwarrow' => '↖',
        'rightsquigarrow' => '⇝',
        'leadsto' => '⇝',
        'Rightarrow' => '⇒',
        'Longrightarrow' => '⇒',
        'Leftarrow' => '⇐',
        'Longleftarrow' => '⇐',
        'Leftrightarrow' => '⇔',
        'Longleftrightarrow' => '⇔',
        'implies' => '⇒',
        'iff' => '⇔',
        'mapsto' => '↦',
        'longmapsto' => '↦',
        'uparrow' => '↑',
        'downarrow' => '↓',
        'partial' => '∂',
        'nabla' => '∇',
        'int' => '∫',
        'iint' => '∬',
        'iiint' => '∭',
        'oint' => '∮',
        'sum' => '∑',
        'prod' => '∏',
        'coprod' => '∐',
        'infty' => '∞',
        'emptyset' => '∅',
        'varnothing' => '∅',
        'angle' => '∠',
        'therefore' => '∴',
        'because' => '∵',
        'aleph' => 'ℵ',
        'beth' => 'ℶ',
        'gimel' => 'ℷ',
        'daleth' => 'ℸ',
        'top' => '⊤',
        'bot' => '⊥',
        'triangle' => '△',
        'square' => '□',
        'lozenge' => '◊',
        'checkmark' => '✓',
        'complement' => '∁',
        'wp' => '℘',
        'prime' => '′',
        'ldots' => '…',
        'dots' => '…',
        'cdots' => '⋯',
        'vdots' => '⋮',
        'ddots' => '⋱',
        'ell' => 'ℓ',
        'hbar' => 'ℏ',
        'Im' => 'ℑ',
        'Re' => 'ℜ',
        'langle' => '⟨',
        'rangle' => '⟩',
        'vert' => '|',
        'lvert' => '|',
        'rvert' => '|',
        'Vert' => '‖',
        'lVert' => '‖',
        'rVert' => '‖',
        'lbrace' => '{',
        'rbrace' => '}',
        'backslash' => '\\',
        'lfloor' => '⌊',
        'rfloor' => '⌋',
        'lceil' => '⌈',
        'rceil' => '⌉',
        'colon' => ':',
    ];

    private const array NEGATED_SYMBOLS = [
        '<' => '≮',
        '>' => '≯',
        '=' => '≠',
        '∈' => '∉',
        '∋' => '∌',
        '∣' => '∤',
        '∥' => '∦',
        '∼' => '≁',
        '≃' => '≄',
        '≅' => '≇',
        '≈' => '≉',
        '≡' => '≢',
        '≤' => '≰',
        '≥' => '≱',
        '≺' => '⊀',
        '≻' => '⊁',
        '⊂' => '⊄',
        '⊃' => '⊅',
        '⊆' => '⊈',
        '⊇' => '⊉',
        '⊢' => '⊬',
        '⊨' => '⊭',
        '↔' => '↮',
        '←' => '↚',
        '→' => '↛',
        '⇒' => '⇏',
        '⇐' => '⇍',
        '⇔' => '⇎',
        '≼' => '⋠',
        '≽' => '⋡',
    ];

    private const array BLACKBOARD = [
        'C' => 'ℂ',
        'H' => 'ℍ',
        'N' => 'ℕ',
        'P' => 'ℙ',
        'Q' => 'ℚ',
        'R' => 'ℝ',
        'Z' => 'ℤ',
    ];

    private const array SUPERSCRIPTS = [
        '0' => '⁰',
        '1' => '¹',
        '2' => '²',
        '3' => '³',
        '4' => '⁴',
        '5' => '⁵',
        '6' => '⁶',
        '7' => '⁷',
        '8' => '⁸',
        '9' => '⁹',
        '+' => '⁺',
        '-' => '⁻',
        '=' => '⁼',
        '(' => '⁽',
        ')' => '⁾',
        'a' => 'ᵃ',
        'b' => 'ᵇ',
        'c' => 'ᶜ',
        'd' => 'ᵈ',
        'e' => 'ᵉ',
        'f' => 'ᶠ',
        'g' => 'ᵍ',
        'h' => 'ʰ',
        'i' => 'ⁱ',
        'j' => 'ʲ',
        'k' => 'ᵏ',
        'l' => 'ˡ',
        'm' => 'ᵐ',
        'n' => 'ⁿ',
        'o' => 'ᵒ',
        'p' => 'ᵖ',
        'r' => 'ʳ',
        's' => 'ˢ',
        't' => 'ᵗ',
        'u' => 'ᵘ',
        'v' => 'ᵛ',
        'w' => 'ʷ',
        'x' => 'ˣ',
        'y' => 'ʸ',
        'z' => 'ᶻ',
    ];

    private const array SUBSCRIPTS = [
        '0' => '₀',
        '1' => '₁',
        '2' => '₂',
        '3' => '₃',
        '4' => '₄',
        '5' => '₅',
        '6' => '₆',
        '7' => '₇',
        '8' => '₈',
        '9' => '₉',
        '+' => '₊',
        '-' => '₋',
        '=' => '₌',
        '(' => '₍',
        ')' => '₎',
        'a' => 'ₐ',
        'e' => 'ₑ',
        'h' => 'ₕ',
        'i' => 'ᵢ',
        'j' => 'ⱼ',
        'k' => 'ₖ',
        'l' => 'ₗ',
        'm' => 'ₘ',
        'n' => 'ₙ',
        'o' => 'ₒ',
        'p' => 'ₚ',
        'r' => 'ᵣ',
        's' => 'ₛ',
        't' => 'ₜ',
        'u' => 'ᵤ',
        'v' => 'ᵥ',
        'x' => 'ₓ',
    ];

    private const array ACCENTS = [
        'acute' => "\u{0301}",
        'bar' => "\u{0305}",
        'breve' => "\u{0306}",
        'check' => "\u{030c}",
        'ddot' => "\u{0308}",
        'dot' => "\u{0307}",
        'grave' => "\u{0300}",
        'hat' => "\u{0302}",
        'mathring' => "\u{030a}",
        'overleftarrow' => "\u{20d6}",
        'overleftrightarrow' => "\u{20e1}",
        'overline' => "\u{0305}",
        'overrightarrow' => "\u{20d7}",
        'tilde' => "\u{0303}",
        'underline' => "\u{0332}",
        'vec' => "\u{20d7}",
        'widehat' => "\u{0302}",
        'widetilde' => "\u{0303}",
    ];

    private const array NAMED_OPERATORS = [
        'arccos' => true,
        'arcsin' => true,
        'arctan' => true,
        'arg' => true,
        'cos' => true,
        'cosh' => true,
        'cot' => true,
        'coth' => true,
        'csc' => true,
        'deg' => true,
        'det' => true,
        'dim' => true,
        'exp' => true,
        'gcd' => true,
        'hom' => true,
        'inf' => true,
        'ker' => true,
        'lg' => true,
        'lim' => true,
        'liminf' => true,
        'limsup' => true,
        'ln' => true,
        'log' => true,
        'max' => true,
        'min' => true,
        'Pr' => true,
        'sec' => true,
        'sin' => true,
        'sinh' => true,
        'sup' => true,
        'tan' => true,
        'tanh' => true,
    ];

    private const array LIMIT_OPERATORS = [
        'argmax' => true,
        'argmin' => true,
        'inf' => true,
        'injlim' => true,
        'lim' => true,
        'liminf' => true,
        'limsup' => true,
        'max' => true,
        'min' => true,
        'projlim' => true,
        'sup' => true,
    ];

    private const array DISPLAY_LIMIT_SYMBOLS = [
        'bigcap' => true,
        'bigcup' => true,
        'bigodot' => true,
        'bigoplus' => true,
        'bigotimes' => true,
        'bigsqcup' => true,
        'biguplus' => true,
        'bigvee' => true,
        'bigwedge' => true,
        'coprod' => true,
        'int' => true,
        'iint' => true,
        'iiint' => true,
        'oint' => true,
        'prod' => true,
        'sum' => true,
    ];

    private const array RELATION_COMMANDS = [
        'Leftarrow' => true,
        'Leftrightarrow' => true,
        'Longleftarrow' => true,
        'Longleftrightarrow' => true,
        'Longrightarrow' => true,
        'Rightarrow' => true,
        'Join' => true,
        'Vdash' => true,
        'Vvdash' => true,
        'approx' => true,
        'asymp' => true,
        'bowtie' => true,
        'cong' => true,
        'dashv' => true,
        'fullouterjoin' => true,
        'doteq' => true,
        'downarrow' => true,
        'equiv' => true,
        'ge' => true,
        'geq' => true,
        'geqslant' => true,
        'gets' => true,
        'gg' => true,
        'hookleftarrow' => true,
        'hookrightarrow' => true,
        'iff' => true,
        'implies' => true,
        'in' => true,
        'leadsto' => true,
        'le' => true,
        'leftarrow' => true,
        'leftharpoondown' => true,
        'leftharpoonup' => true,
        'leftrightarrow' => true,
        'leftrightharpoons' => true,
        'leftouterjoin' => true,
        'leq' => true,
        'leqslant' => true,
        'll' => true,
        'longleftarrow' => true,
        'longleftrightarrow' => true,
        'longmapsto' => true,
        'longrightarrow' => true,
        'ltimes' => true,
        'mapsto' => true,
        'mid' => true,
        'models' => true,
        'ne' => true,
        'nearrow' => true,
        'neq' => true,
        'ni' => true,
        'notin' => true,
        'nvdash' => true,
        'nvDash' => true,
        'nwarrow' => true,
        'parallel' => true,
        'perp' => true,
        'prec' => true,
        'preceq' => true,
        'propto' => true,
        'rightharpoondown' => true,
        'rightharpoonup' => true,
        'rightleftharpoons' => true,
        'rightouterjoin' => true,
        'rightarrow' => true,
        'rightsquigarrow' => true,
        'rtimes' => true,
        'searrow' => true,
        'sim' => true,
        'simeq' => true,
        'sqsubset' => true,
        'sqsubseteq' => true,
        'sqsupset' => true,
        'sqsupseteq' => true,
        'subset' => true,
        'subseteq' => true,
        'succ' => true,
        'succeq' => true,
        'supset' => true,
        'supseteq' => true,
        'swarrow' => true,
        'to' => true,
        'triangleleft' => true,
        'triangleright' => true,
        'twoheadleftarrow' => true,
        'twoheadrightarrow' => true,
        'uparrow' => true,
        'vdash' => true,
    ];

    private const array SPACING_COMMANDS = [
        ',' => true,
        ':' => true,
        ';' => true,
        ' ' => true,
        '>' => true,
        'enspace' => true,
        'enskip' => true,
        'medspace' => true,
        'quad' => true,
        'qquad' => true,
        'thickspace' => true,
        'thinspace' => true,
    ];

    private const array NEGATIVE_SPACING_COMMANDS = [
        '!' => true,
        'negmedspace' => true,
        'negthickspace' => true,
        'negthinspace' => true,
    ];

    private const array FONT_SWITCH_COMMANDS = [
        'bf' => true,
        'cal' => true,
        'it' => true,
        'rm' => true,
        'sf' => true,
        'sl' => true,
        'tt' => true,
    ];

    private const array IGNORED_COMMANDS = [
        'displaystyle' => true,
        'limits' => true,
        'nolimits' => true,
        'scriptstyle' => true,
        'scriptscriptstyle' => true,
        'textstyle' => true,
    ];

    private const array SIZE_COMMANDS = [
        'big' => true,
        'Big' => true,
        'bigg' => true,
        'Bigg' => true,
        'bigl' => true,
        'Bigl' => true,
        'biggl' => true,
        'Biggl' => true,
        'bigr' => true,
        'Bigr' => true,
        'biggr' => true,
        'Biggr' => true,
    ];

    private const array PLAIN_WRAPPERS = [
        'emph' => true,
        'mathcal' => true,
        'mathbf' => true,
        'mathfrak' => true,
        'mathit' => true,
        'mathrm' => true,
        'mathnormal' => true,
        'mathscr' => true,
        'mathsf' => true,
        'mathtt' => true,
        'mathup' => true,
        'mbox' => true,
        'overbrace' => true,
        'pmb' => true,
        'smash' => true,
        'substack' => true,
        'text' => true,
        'textbf' => true,
        'textit' => true,
        'textmd' => true,
        'textnormal' => true,
        'textrm' => true,
        'textsc' => true,
        'textsf' => true,
        'textsl' => true,
        'texttt' => true,
        'textup' => true,
        'underbrace' => true,
        'bm' => true,
        'boldsymbol' => true,
    ];

    /** @var list<string> */
    private readonly array $chars;

    private readonly int $length;

    /** @var list<array<string, mixed>> shared by every nested parser of one expression */
    private array $layoutNodes;

    private int $position = 0;

    private bool $supported = true;

    private bool $stackFractions = true;

    private int $scriptDepth = 0;

    /** @param list<array<string, mixed>> $layoutNodes */
    private function __construct(string $source, array &$layoutNodes, private readonly bool $display)
    {
        $this->chars = mb_str_split($source, 1, 'UTF-8');
        $this->length = count($this->chars);
        $this->layoutNodes = &$layoutNodes;
    }

    /**
     * Render a basic LaTeX math expression as terminal-friendly Unicode text.
     * Returns null when the expression contains unsupported or malformed syntax.
     *
     * @param bool $display stack fractions and operator limits vertically for display math
     */
    public static function render(string $source, bool $display = false): ?string
    {
        $layoutNodes = [];
        $rendered = (new self($source, $layoutNodes, $display))->run();
        if ($rendered === null) {
            return null;
        }
        if ($layoutNodes === []) {
            return str_replace(self::PROTECTED_SPACE, ' ', $rendered);
        }
        $lines = self::renderLayout($rendered, $layoutNodes)['lines'];
        $indentation = PHP_INT_MAX;
        foreach ($lines as $line) {
            if (self::trim($line) !== '') {
                $indentation = min($indentation, mb_strlen($line, 'UTF-8') - mb_strlen(self::trimStart($line), 'UTF-8'));
            }
        }

        return str_replace(
            self::PROTECTED_SPACE,
            ' ',
            self::trimEnd(implode("\n", array_map(
                static fn (string $line): string => self::trimEnd(mb_substr($line, $indentation, null, 'UTF-8')),
                $lines,
            ))),
        );
    }

    // ---- JavaScript's string semantics ---------------------------------------------------------

    private static function isSpace(string $character): bool
    {
        return $character !== '' && preg_match('/^[' . self::WS . ']$/uD', $character) === 1;
    }

    private static function trimStart(string $value): string
    {
        return preg_replace('/^[' . self::WS . ']+/u', '', $value) ?? $value;
    }

    private static function trimEnd(string $value): string
    {
        return preg_replace('/[' . self::WS . ']+$/uD', '', $value) ?? $value;
    }

    private static function trim(string $value): string
    {
        return self::trimEnd(self::trimStart($value));
    }

    /** `Array.from(value).length`. */
    private static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    // ---- formatting ----------------------------------------------------------------------------

    /** @param array<array-key, string> $replacements */
    private static function replaceCharacters(string $value, array $replacements): ?string
    {
        $result = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $character) {
            $replacement = $replacements[$character] ?? null;
            if ($replacement === null) {
                return null;
            }
            $result .= $replacement;
        }

        return $result;
    }

    private static function normalizeScriptValue(string $value): string
    {
        return preg_replace('/[' . self::WS . ']*([=+-])[' . self::WS . ']*/u', '$1', self::trim($value)) ?? $value;
    }

    private static function formatUnicodeScript(string $value, string $kind): ?string
    {
        return self::replaceCharacters(self::normalizeScriptValue($value), $kind === 'sub' ? self::SUBSCRIPTS : self::SUPERSCRIPTS);
    }

    private static function formatScript(string $value, string $kind): string
    {
        $value = self::normalizeScriptValue($value);
        $unicode = self::formatUnicodeScript($value, $kind);
        if ($unicode !== null) {
            return $unicode;
        }

        $prefix = $kind === 'sub' ? '_' : '^';
        if (self::length($value) === 1 || ($kind === 'sub' && preg_match('/^[A-Za-z]+$/D', $value) === 1)) {
            return "{$prefix}{$value}";
        }

        return "{$prefix}({$value})";
    }

    private static function formatFraction(string $numerator, string $denominator): string
    {
        $numerator = self::trim($numerator);
        $denominator = self::trim($denominator);
        $simpleNumerator = preg_match('/^[\p{L}\p{N}.]+$/uD', $numerator) === 1;
        $simpleDenominator = preg_match('/^[\p{N}.]+$/uD', $denominator) === 1 || self::length($denominator) === 1;

        return ($simpleNumerator ? $numerator : "({$numerator})") . '/' . ($simpleDenominator ? $denominator : "({$denominator})");
    }

    private static function formatRoot(string $value, string $symbol = '√'): string
    {
        $value = self::trim($value);

        return preg_match('/^[\p{L}\p{N}.]+$/uD', $value) === 1 ? "{$symbol}{$value}" : "{$symbol}({$value})";
    }

    private static function normalizeOutput(string $value): string
    {
        $value = preg_replace('/(?<=[\p{L}\p{N})\]}\x{F0001}])\x{F0004}/u', ' ', $value) ?? $value;
        $value = str_replace(self::NAMED_OPERATOR_START, '', $value);
        $value = preg_replace('/\x{F0005}(?=[\p{L}\p{N}√\x{F0000}])/u', ' ', $value) ?? $value;
        $value = str_replace(self::NAMED_OPERATOR_END, '', $value);
        $lines = array_map(
            static fn (string $line): string => self::trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line),
            explode("\n", $value),
        );
        $count = count($lines);
        $kept = [];
        foreach ($lines as $index => $line) {
            if ($line !== '' || ($index > 0 && $index < $count - 1)) {
                $kept[] = $line;
            }
        }

        return self::trim(implode("\n", $kept));
    }

    // ---- layout --------------------------------------------------------------------------------

    private static function padLayoutLine(string $line, int $width, bool $centered = false): string
    {
        $padding = max(0, $width - Width::visible($line));
        $left = $centered ? intdiv($padding, 2) : 0;

        return str_repeat(' ', $left) . $line . str_repeat(' ', $padding - $left);
    }

    /**
     * @param list<array{lines: list<string>, width: int, baseline: int}> $layouts
     * @return array{lines: list<string>, width: int, baseline: int}
     */
    private static function joinLayouts(array $layouts): array
    {
        if ($layouts === []) {
            return ['lines' => [''], 'width' => 0, 'baseline' => 0];
        }
        $baseline = max(array_map(static fn (array $layout): int => $layout['baseline'], $layouts));
        $below = max(array_map(static fn (array $layout): int => count($layout['lines']) - $layout['baseline'] - 1, $layouts));
        $lines = [];
        for ($row = 0; $row <= $baseline + $below; $row++) {
            $line = '';
            foreach ($layouts as $layout) {
                $sourceRow = $row - $baseline + $layout['baseline'];
                $line .= $sourceRow >= 0 && $sourceRow < count($layout['lines'])
                    ? self::padLayoutLine($layout['lines'][$sourceRow], $layout['width'])
                    : str_repeat(' ', $layout['width']);
            }
            $lines[] = self::trimEnd($line);
        }

        return [
            'lines' => $lines,
            'width' => array_sum(array_map(static fn (array $layout): int => $layout['width'], $layouts)),
            'baseline' => $baseline,
        ];
    }

    /** @param list<string> $lines */
    private static function widest(array $lines): int
    {
        $width = 0;
        foreach ($lines as $line) {
            $width = max($width, Width::visible($line));
        }

        return $width;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return array{lines: list<string>, width: int, baseline: int}
     */
    private static function renderLayout(string $source, array $nodes): array
    {
        $renderedLines = [];
        $firstBaseline = 0;
        $first = true;
        foreach (explode("\n", $source) as $sourceLine) {
            $layouts = [];
            $position = 0;
            $previousNode = null;
            preg_match_all('/\x{F0000}([0-9]+)\x{F0001}/u', $sourceLine, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $index = $match[0][1];
                $node = $nodes[(int) $match[1][0]] ?? null;
                if ($node === null) {
                    continue;
                }
                if ($index > $position) {
                    $sliced = substr($sourceLine, $position, $index - $position);
                    $trimmed = self::trimEnd($previousNode !== null ? self::trimStart($sliced) : $sliced);
                    $preserveLeadingSpace = ($previousNode['type'] ?? null) === 'matrix' && self::startsWithSpace($sliced);
                    $preserveTrailingSpace = $node['type'] === 'matrix' && self::endsWithSpace($sliced);
                    $text = $trimmed !== ''
                        ? ($preserveLeadingSpace ? ' ' : '') . $trimmed . ($preserveTrailingSpace ? ' ' : '')
                        : ($preserveLeadingSpace || $preserveTrailingSpace ? ' ' : '');
                    $layouts[] = ['lines' => [$text], 'width' => Width::visible($text), 'baseline' => 0];
                }
                if ($node['type'] === 'fraction') {
                    $numerator = self::renderLayout($node['numerator'], $nodes);
                    $denominator = self::renderLayout($node['denominator'], $nodes);
                    $contentWidth = max($numerator['width'], $denominator['width'], 1);
                    $width = $contentWidth + 2;
                    $layouts[] = [
                        'lines' => [
                            ...array_map(static fn (string $line): string => self::padLayoutLine($line, $width, true), $numerator['lines']),
                            ' ' . str_repeat('─', $contentWidth) . ' ',
                            ...array_map(static fn (string $line): string => self::padLayoutLine($line, $width, true), $denominator['lines']),
                        ],
                        'width' => $width,
                        'baseline' => count($numerator['lines']),
                    ];
                } elseif ($node['type'] === 'operator') {
                    $contentWidth = max(
                        Width::visible($node['operator']),
                        $node['lower'] === null ? 0 : Width::visible($node['lower']),
                        $node['upper'] === null ? 0 : Width::visible($node['upper']),
                    );
                    $lines = [];
                    if ($node['upper'] !== null) {
                        $lines[] = self::padLayoutLine($node['upper'], $contentWidth, true) . ' ';
                    }
                    $lines[] = self::padLayoutLine($node['operator'], $contentWidth, true) . ' ';
                    if ($node['lower'] !== null) {
                        $lines[] = self::padLayoutLine($node['lower'], $contentWidth, true) . ' ';
                    }
                    $layouts[] = [
                        'lines' => $lines,
                        'width' => $contentWidth + 1,
                        'baseline' => $node['upper'] === null ? 0 : 1,
                    ];
                } elseif ($node['type'] === 'script') {
                    $upper = $node['upper'] === null ? null : self::renderLayout($node['upper'], $nodes);
                    $lower = $node['lower'] === null ? null : self::renderLayout($node['lower'], $nodes);
                    $width = max($upper['width'] ?? 0, $lower['width'] ?? 0);
                    $layouts[] = [
                        'lines' => [
                            ...array_map(static fn (string $line): string => self::padLayoutLine($line, $width), $upper['lines'] ?? []),
                            str_repeat(' ', $width),
                            ...array_map(static fn (string $line): string => self::padLayoutLine($line, $width), $lower['lines'] ?? []),
                        ],
                        'width' => $width,
                        'baseline' => count($upper['lines'] ?? []),
                    ];
                } else {
                    $width = self::widest($node['lines']);
                    $layouts[] = [
                        'lines' => array_map(static fn (string $line): string => self::padLayoutLine($line, $width), $node['lines']),
                        'width' => $width,
                        'baseline' => $node['baseline'],
                    ];
                }
                $position = $index + strlen($match[0][0]);
                $previousNode = $node;
            }
            if ($position < strlen($sourceLine)) {
                $sliced = substr($sourceLine, $position);
                $trimmed = $previousNode !== null ? self::trimStart($sliced) : $sliced;
                $text = ($previousNode['type'] ?? null) === 'matrix' && self::startsWithSpace($sliced) ? " {$trimmed}" : $trimmed;
                $layouts[] = ['lines' => [$text], 'width' => Width::visible($text), 'baseline' => 0];
            }
            $lineLayout = self::joinLayouts($layouts);
            if ($first) {
                $firstBaseline = $lineLayout['baseline'];
                $first = false;
            }
            array_push($renderedLines, ...$lineLayout['lines']);
        }

        return [
            'lines' => $renderedLines,
            'width' => self::widest($renderedLines),
            'baseline' => $firstBaseline,
        ];
    }

    private static function startsWithSpace(string $value): bool
    {
        return preg_match('/^[' . self::WS . ']/u', $value) === 1;
    }

    private static function endsWithSpace(string $value): bool
    {
        return preg_match('/[' . self::WS . ']$/uD', $value) === 1;
    }

    // ---- the parser ----------------------------------------------------------------------------

    private function char(int $index): string
    {
        return $this->chars[$index] ?? '';
    }

    private function slice(int $start, ?int $end = null): string
    {
        return implode('', array_slice($this->chars, $start, $end === null ? null : $end - $start));
    }

    /** `String.prototype.indexOf`, in code points; -1 when absent. */
    private function indexOf(string $needle, int $from): int
    {
        $tail = $this->slice($from);
        $at = strpos($tail, $needle);

        return $at === false ? -1 : $from + self::length(substr($tail, 0, $at));
    }

    private function pushNode(array $node): string
    {
        $this->layoutNodes[] = $node;

        return self::LAYOUT_MARKER_START . (count($this->layoutNodes) - 1) . self::LAYOUT_MARKER_END;
    }

    private function run(): ?string
    {
        $rendered = $this->parseSequence();
        if (!$this->supported || $this->position !== $this->length) {
            return null;
        }

        return self::normalizeOutput($rendered);
    }

    private function parseSequence(?string $endCharacter = null): string
    {
        $result = '';
        while ($this->position < $this->length) {
            $character = $this->chars[$this->position];
            if ($endCharacter !== null && $character === $endCharacter) {
                $this->position++;

                return $result;
            }

            if ($character === '}') {
                $this->supported = false;

                return $result;
            }

            if ($character === '{') {
                $this->position++;
                $result .= $this->parseSequence('}');

                continue;
            }

            if ($character === '\\') {
                $command = $this->parseCommand();
                if ($command === self::NEGATIVE_SPACE) {
                    $result = self::trimEnd($result);
                    if (str_ends_with($result, self::NAMED_OPERATOR_END)) {
                        $result = substr($result, 0, -strlen(self::NAMED_OPERATOR_END));
                    }
                } else {
                    $result .= $command;
                }

                continue;
            }

            if ($character === '^' || $character === '_') {
                $this->position++;
                $result = self::trimEnd($result);
                $script = $this->parseScripts($character);
                if (str_ends_with($result, self::NAMED_OPERATOR_END)) {
                    $result = substr($result, 0, -strlen(self::NAMED_OPERATOR_END)) . $script . self::NAMED_OPERATOR_END;
                } else {
                    $result .= $script;
                }

                continue;
            }

            if (self::isSpace($character)) {
                $result .= $this->parseWhitespace();

                continue;
            }

            if ($character === '=' || $character === '<' || $character === '>') {
                $result = self::trimEnd($result) . " {$character} ";
                $this->position++;

                continue;
            }

            if ($character === '&') {
                $this->position++;

                continue;
            }

            if ($character === '~') {
                $this->position++;
                $result .= ' ';

                continue;
            }

            if ($character === '.' && preg_match('/\x{F0000}([0-9]+)\x{F0001}$/uD', $result, $marker) === 1) {
                $index = (int) $marker[1];
                if (($this->layoutNodes[$index]['type'] ?? null) === 'matrix') {
                    $lastLine = count($this->layoutNodes[$index]['lines']) - 1;
                    $this->layoutNodes[$index]['lines'][$lastLine] = ($this->layoutNodes[$index]['lines'][$lastLine] ?? '') . $character;
                    $this->position++;

                    continue;
                }
            }

            $result .= $character;
            $this->position++;
        }

        if ($endCharacter !== null) {
            $this->supported = false;
        }

        return $result;
    }

    private function parseScripts(string $initialMarker): string
    {
        $scripts = ['sub' => null, 'sup' => null];
        $order = [];
        $parse = function (string $marker) use (&$scripts, &$order): void {
            $kind = $marker === '_' ? 'sub' : 'sup';
            $this->scriptDepth++;
            try {
                $scripts[$kind] = $this->parseRequiredArgument(false);
            } finally {
                $this->scriptDepth--;
            }
            $order[] = $kind;
        };

        $parse($initialMarker);
        $nextPosition = $this->position;
        while ($nextPosition < $this->length && self::isSpace($this->char($nextPosition))) {
            $nextPosition++;
        }
        $nextMarker = $this->char($nextPosition);
        if (($nextMarker === '^' || $nextMarker === '_') && $nextMarker !== $initialMarker) {
            $this->position = $nextPosition + 1;
            $parse($nextMarker);
        }

        $subUnicode = $scripts['sub'] === null ? null : self::formatUnicodeScript($scripts['sub'], 'sub');
        $supUnicode = $scripts['sup'] === null ? null : self::formatUnicodeScript($scripts['sup'], 'sup');
        $canUseLayout = true;
        foreach ([$scripts['sub'], $scripts['sup']] as $value) {
            if (
                $value !== null
                && (str_contains($value, '/')
                    || (!str_contains($value, self::LAYOUT_MARKER_START) && self::length($value) > 1 && preg_match('/[A-Z*∗]/u', $value) !== 1))
            ) {
                $canUseLayout = false;
            }
        }
        $needsLayout = $this->display
            && $canUseLayout
            && ($this->scriptDepth > 0
                || ($scripts['sub'] !== null && $subUnicode === null)
                || ($scripts['sup'] !== null && $supUnicode === null));
        if (!$needsLayout) {
            return implode('', array_map(
                static fn (string $kind): string => $kind === 'sub'
                    ? ($subUnicode ?? self::formatScript($scripts['sub'] ?? '', $kind))
                    : ($supUnicode ?? self::formatScript($scripts['sup'] ?? '', $kind)),
                $order,
            ));
        }

        return $this->pushNode([
            'type' => 'script',
            'lower' => $scripts['sub'] === null ? null : self::normalizeOutput($scripts['sub']),
            'upper' => $scripts['sup'] === null ? null : self::normalizeOutput($scripts['sup']),
        ]);
    }

    private function parseWhitespace(): string
    {
        while ($this->position < $this->length && self::isSpace($this->char($this->position))) {
            $this->position++;
        }

        return ' ';
    }

    private function parseCommand(): string
    {
        $this->position++;
        if ($this->position >= $this->length) {
            $this->supported = false;

            return '';
        }

        $first = $this->char($this->position);
        if ($first === "\n" || $first === "\r") {
            $this->position++;
            if ($first === "\r" && $this->char($this->position) === "\n") {
                $this->position++;
            }

            return ' ';
        }
        if (preg_match('/^[A-Za-z]$/D', $first) === 1) {
            $start = $this->position;
            while ($this->position < $this->length && preg_match('/^[A-Za-z]$/D', $this->char($this->position)) === 1) {
                $this->position++;
            }
            $command = $this->slice($start, $this->position);
        } else {
            $command = $first;
            $this->position++;
        }

        if ($command === '\\') {
            return "\n";
        }
        if (isset(self::SPACING_COMMANDS[$command])) {
            return ' ';
        }
        if (isset(self::NEGATIVE_SPACING_COMMANDS[$command])) {
            return self::NEGATIVE_SPACE;
        }
        if (isset(self::FONT_SWITCH_COMMANDS[$command])) {
            while ($this->position < $this->length && self::isSpace($this->char($this->position))) {
                $this->position++;
            }

            return '';
        }
        if (isset(self::IGNORED_COMMANDS[$command])) {
            return '';
        }
        if (in_array($command, ['{', '}', '$', '%', '#', '_', '&'], true)) {
            return $command;
        }
        if ($command === '|') {
            return '‖';
        }
        if ($command === 'not') {
            $value = self::trim($this->parseRequiredArgument(false));
            $negated = self::NEGATED_SYMBOLS[$value] ?? null;
            if ($negated !== null) {
                return " {$negated} ";
            }
            $characters = mb_str_split($value, 1, 'UTF-8');
            if ($characters === []) {
                $this->supported = false;

                return '';
            }

            return ' ' . $characters[0] . "\u{0338}" . implode('', array_slice($characters, 1)) . ' ';
        }
        if (isset(self::LIMIT_OPERATORS[$command])) {
            return $this->parseOperator($command, 'bracket', true, true);
        }

        $symbol = self::SYMBOLS[$command] ?? null;
        if ($symbol !== null) {
            if (isset(self::DISPLAY_LIMIT_SYMBOLS[$command])) {
                return $this->parseOperator($symbol, 'script', true);
            }

            return $command === 'cdot' || $command === 'times' || isset(self::RELATION_COMMANDS[$command]) ? " {$symbol} " : $symbol;
        }
        if (isset(self::NAMED_OPERATORS[$command])) {
            return self::NAMED_OPERATOR_START . $command . self::NAMED_OPERATOR_END;
        }
        if (isset(self::SIZE_COMMANDS[$command])) {
            return '';
        }
        if ($command === 'left' || $command === 'middle' || $command === 'right') {
            if ($this->char($this->position) === '.') {
                $this->position++;
            }

            return '';
        }
        if ($command === 'frac' || $command === 'dfrac' || $command === 'tfrac') {
            $shouldStack = $this->display && $this->stackFractions && $command !== 'tfrac';
            $numerator = $this->parseRequiredArgument(!$shouldStack);
            $denominator = $this->parseRequiredArgument(!$shouldStack);
            if ($shouldStack) {
                return $this->pushNode([
                    'type' => 'fraction',
                    'numerator' => self::normalizeOutput($numerator),
                    'denominator' => self::normalizeOutput($denominator),
                ]);
            }

            return self::formatFraction($numerator, $denominator);
        }
        if ($command === 'sqrt') {
            $degree = $this->parseOptionalArgument();
            $degree = $degree === null ? null : self::trim($degree);
            $value = $this->parseRequiredArgument();
            if ($degree === null || $degree === '2') {
                return self::formatRoot($value);
            }
            if ($degree === '3') {
                return self::formatRoot($value, '∛');
            }
            if ($degree === '4') {
                return self::formatRoot($value, '∜');
            }

            return self::formatScript($degree, 'sup') . self::formatRoot($value);
        }
        if ($command === 'boxed' || $command === 'fbox') {
            return '[' . self::trim($this->parseRequiredArgument()) . ']';
        }
        if ($command === 'binom' || $command === 'dbinom' || $command === 'tbinom') {
            $top = $this->parseRequiredArgument();

            return "({$top} choose {$this->parseRequiredArgument()})";
        }
        $accent = self::ACCENTS[$command] ?? null;
        if ($accent !== null) {
            $value = $this->parseRequiredArgument();

            return self::length($value) === 1 ? "{$value}{$accent}" : "{$command}({$value})";
        }
        if ($command === 'mathbb') {
            $value = $this->parseRequiredArgument();

            return implode('', array_map(
                static fn (string $character): string => self::BLACKBOARD[$character] ?? $character,
                mb_str_split($value, 1, 'UTF-8'),
            ));
        }
        if ($command === 'operatorname') {
            $starred = $this->char($this->position) === '*';
            if ($starred) {
                $this->position++;
            }
            $operator = self::trim(self::normalizeOutput($this->parseRequiredArgument()));

            return $this->parseOperator($operator, 'bracket', $starred, true);
        }
        if ($command === 'mod' || $command === 'bmod') {
            return ' mod ';
        }
        if ($command === 'pmod' || $command === 'pod') {
            $value = self::trim($this->parseRequiredArgument());

            return $command === 'pmod' ? " (mod {$value})" : " ({$value})";
        }
        if ($command === 'overset' || $command === 'stackrel') {
            $upper = $this->parseRequiredArgument();
            $value = self::trim($this->parseRequiredArgument());

            return $value . self::formatScript($upper, 'sup');
        }
        if ($command === 'underset') {
            $lower = $this->parseRequiredArgument();
            $value = self::trim($this->parseRequiredArgument());

            return $value . self::formatScript($lower, 'sub');
        }
        if (isset(self::PLAIN_WRAPPERS[$command])) {
            $value = $this->parseRequiredArgument();

            return str_starts_with($command, 'text') || $command === 'mbox' ? $value : self::trim($value);
        }
        if ($command === 'begin') {
            return $this->parseEnvironment();
        }
        if ($command === 'end') {
            $this->supported = false;

            return '';
        }

        $this->supported = false;

        return "\\{$command}";
    }

    private function parseOperator(string $operator, string $inlineLowerStyle, bool $displayLimits, bool $spaced = false): string
    {
        $useDisplayLimits = $displayLimits;
        $modifierPosition = $this->position;
        while ($modifierPosition < $this->length && ($this->char($modifierPosition) === ' ' || $this->char($modifierPosition) === "\t")) {
            $modifierPosition++;
        }
        if (preg_match('/^\\\\(limits|nolimits)(?![A-Za-z])/', $this->slice($modifierPosition), $modifier) === 1) {
            $useDisplayLimits = $modifier[1] === 'limits';
            $this->position = $modifierPosition + strlen($modifier[0]);
        }

        $lower = null;
        $upper = null;
        while (true) {
            $scriptPosition = $this->position;
            while ($scriptPosition < $this->length && ($this->char($scriptPosition) === ' ' || $this->char($scriptPosition) === "\t")) {
                $scriptPosition++;
            }
            $kind = $this->char($scriptPosition);
            if ($kind !== '_' && $kind !== '^') {
                break;
            }
            $this->position = $scriptPosition + 1;
            $value = str_replace(' ', '', self::normalizeOutput($this->parseRequiredArgument(false)));
            if ($kind === '_') {
                if ($lower !== null) {
                    $this->supported = false;
                }
                $lower = $value;
            } else {
                if ($upper !== null) {
                    $this->supported = false;
                }
                $upper = $value;
            }
        }

        if ($this->display && $useDisplayLimits && ($lower !== null || $upper !== null)) {
            return $this->pushNode(['type' => 'operator', 'operator' => $operator, 'lower' => $lower, 'upper' => $upper]);
        }

        $rendered = $operator;
        if ($lower !== null) {
            $rendered .= $inlineLowerStyle === 'bracket' ? "[{$lower}]" : self::formatScript($lower, 'sub');
        }
        if ($upper !== null) {
            $rendered .= self::formatScript($upper, 'sup');
        }

        return $spaced ? " {$rendered} " : $rendered;
    }

    private function parseRequiredArgument(bool $stackFractions = true): string
    {
        $previousStackFractions = $this->stackFractions;
        $this->stackFractions = $previousStackFractions && $stackFractions;
        $value = $this->parseRequiredArgumentValue();
        $this->stackFractions = $previousStackFractions;

        return $value;
    }

    private function parseRequiredArgumentValue(): string
    {
        while ($this->position < $this->length && self::isSpace($this->char($this->position))) {
            $this->position++;
        }
        if ($this->position >= $this->length) {
            $this->supported = false;

            return '';
        }
        if ($this->char($this->position) === '{') {
            $this->position++;

            return $this->parseSequence('}');
        }
        if ($this->char($this->position) === '\\') {
            return $this->parseCommand();
        }
        $value = $this->char($this->position);
        $this->position++;

        return $value;
    }

    private function parseOptionalArgument(): ?string
    {
        while ($this->position < $this->length && ($this->char($this->position) === ' ' || $this->char($this->position) === "\t")) {
            $this->position++;
        }
        if ($this->char($this->position) !== '[') {
            return null;
        }
        $end = $this->indexOf(']', $this->position + 1);
        if ($end < 0) {
            $this->supported = false;

            return null;
        }
        $value = $this->slice($this->position + 1, $end);
        $this->position = $end + 1;

        return $this->renderNested($value);
    }

    private function readRawGroup(): ?string
    {
        while ($this->position < $this->length && ($this->char($this->position) === ' ' || $this->char($this->position) === "\t")) {
            $this->position++;
        }
        if ($this->char($this->position) !== '{') {
            $this->supported = false;

            return null;
        }

        $start = ++$this->position;
        $depth = 1;
        while ($this->position < $this->length) {
            $character = $this->chars[$this->position];
            if ($character === '\\') {
                $this->position += 2;

                continue;
            }
            if ($character === '{') {
                $depth++;
            }
            if ($character === '}') {
                $depth--;
            }
            if ($depth === 0) {
                $value = $this->slice($start, $this->position);
                $this->position++;

                return $value;
            }
            $this->position++;
        }
        $this->supported = false;

        return null;
    }

    /** @return list<string> */
    private static function splitEnvironmentRows(string $body): array
    {
        return preg_split('/\\\\\\\\(?:\[[^\]\n]*\])?/', $body) ?: [$body];
    }

    private function parseEnvironment(): string
    {
        $environment = $this->readRawGroup();
        if ($environment === null || $environment === '') {
            return '';
        }
        $endMarker = "\\end{{$environment}}";
        $end = $this->indexOf($endMarker, $this->position);
        if ($end < 0) {
            $this->supported = false;

            return '';
        }
        $body = $this->slice($this->position, $end);
        $this->position = $end + self::length($endMarker);

        if ($environment === 'equation' || $environment === 'equation*' || $environment === 'displaymath') {
            return self::trim($this->renderNested($body));
        }

        if (in_array($environment, [
            'aligned', 'align', 'align*', 'alignedat', 'alignat', 'alignat*', 'gather', 'gathered', 'multline', 'multline*', 'split',
        ], true)) {
            $alignedAt = in_array($environment, ['alignedat', 'alignat', 'alignat*'], true);
            $alignedBody = $alignedAt ? (preg_replace('/^[' . self::WS . ']*\{[^}]*\}/u', '', $body, 1) ?? $body) : $body;
            $rows = [];
            foreach (self::splitEnvironmentRows($alignedBody) as $row) {
                $cells = explode('&', $row);
                if ($alignedAt) {
                    $pairs = [];
                    for ($index = 0; $index < (int) ceil(count($cells) / 2); $index++) {
                        $pairs[] = implode('', array_slice($cells, $index * 2, 2));
                    }
                    $source = implode(' ', $pairs);
                } else {
                    $source = implode('', $cells);
                }
                $rendered = self::trim($this->renderNested($source));
                if ($rendered !== '') {
                    $rows[] = $rendered;
                }
            }

            return implode("\n", $rows);
        }

        if ($environment === 'cases' || $environment === 'cases*') {
            return $this->renderCases($body);
        }

        if (in_array($environment, ['array', 'matrix', 'smallmatrix', 'pmatrix', 'bmatrix', 'Bmatrix', 'vmatrix', 'Vmatrix'], true)) {
            $matrixBody = $environment === 'array' ? (preg_replace('/^[' . self::WS . ']*\{[^}]*\}/u', '', $body, 1) ?? $body) : $body;

            return $this->renderMatrix($environment, $matrixBody);
        }

        $this->supported = false;

        return $body;
    }

    /** @return list<list<string>> rows of rendered cells, rows with no content left out */
    private function renderRows(string $body): array
    {
        $rows = [];
        foreach (self::splitEnvironmentRows($body) as $row) {
            $cells = array_map(fn (string $cell): string => self::trim($this->renderNested($cell, false)), explode('&', $row));
            if (array_filter($cells, static fn (string $cell): bool => $cell !== '') !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function renderCases(string $body): string
    {
        $rows = $this->renderRows($body);
        $stripComma = static fn (string $value): string => preg_replace('/,[' . self::WS . ']*$/uD', '', $value) ?? $value;
        $valueWidth = 0;
        foreach ($rows as $row) {
            $valueWidth = max($valueWidth, Width::visible($stripComma($row[0] ?? '')));
        }
        $contents = [];
        foreach ($rows as $row) {
            $value = $stripComma($row[0] ?? '');
            $condition = $row[1] ?? '';
            if ($condition === '') {
                $contents[] = $value;

                continue;
            }
            $conditionPrefix = preg_match('/^(?:if|when|for|otherwise)\b/i', $condition) === 1 ? ' ' : ' if ';
            $contents[] = $value . str_repeat(self::PROTECTED_SPACE, max(0, $valueWidth - Width::visible($value))) . $conditionPrefix . $condition;
        }
        if (count($contents) <= 1) {
            return $contents === [] ? '' : "⎧ {$contents[0]}";
        }

        $middle = intdiv(count($contents), 2);
        $visualRows = $contents;
        if (count($contents) % 2 === 0) {
            array_splice($visualRows, $middle, 0, [null]);
        }
        $last = count($visualRows) - 1;
        $lines = [];
        foreach ($visualRows as $index => $content) {
            $delimiter = $index === 0 ? '⎧' : ($index === $last ? '⎩' : '⎨');
            $lines[] = $content === null ? $delimiter : "{$delimiter} {$content}";
        }

        return $this->pushNode(['type' => 'matrix', 'lines' => $lines, 'baseline' => $middle]);
    }

    private function renderMatrix(string $environment, string $body): string
    {
        $matrix = $this->renderRows($body);
        $columnCount = 0;
        foreach ($matrix as $row) {
            $columnCount = max($columnCount, count($row));
        }
        $columnWidths = [];
        for ($column = 0; $column < $columnCount; $column++) {
            $width = 0;
            foreach ($matrix as $row) {
                $width = max($width, Width::visible($row[$column] ?? ''));
            }
            $columnWidths[] = $width;
        }
        $rows = [];
        foreach ($matrix as $row) {
            $cells = [];
            for ($column = 0; $column < $columnCount; $column++) {
                $cell = $row[$column] ?? '';
                $cells[] = $cell . str_repeat(self::PROTECTED_SPACE, max(0, $columnWidths[$column] - Width::visible($cell)));
            }
            $rows[] = implode(' │ ', $cells);
        }

        if ($environment === 'array' || $environment === 'matrix' || $environment === 'smallmatrix') {
            $lines = $rows;
        } else {
            $delimiter = self::MATRIX_DELIMITERS[$environment] ?? null;
            if ($delimiter === null) {
                $this->supported = false;

                return implode("\n", $rows);
            }
            $last = count($rows) - 1;
            $lines = [];
            foreach ($rows as $index => $row) {
                $left = $index === 0 ? $delimiter[0] : ($index === $last ? $delimiter[4] : $delimiter[2]);
                $right = $index === 0 ? $delimiter[1] : ($index === $last ? $delimiter[5] : $delimiter[3]);
                $lines[] = "{$left} {$row} {$right}";
            }
        }

        if (count($lines) <= 1) {
            return $lines[0] ?? '';
        }

        return $this->pushNode(['type' => 'matrix', 'lines' => $lines, 'baseline' => 0]);
    }

    private function renderNested(string $source, bool $stackFractions = true): string
    {
        $rendered = (new self($source, $this->layoutNodes, $this->display && $stackFractions))->run();
        if ($rendered === null) {
            $this->supported = false;

            return $source;
        }

        return $rendered;
    }
}
