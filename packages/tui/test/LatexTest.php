<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Tui\Latex;

/**
 * Upstream's `latex.test.ts`, case for case: every `renderLatex()` assertion there is one row here,
 * named after the `describe` / `it` it came from. A `null` expectation is upstream's `undefined` —
 * the source holds something the renderer does not support, so the caller shows it as written.
 */
final class LatexTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, bool}> */
    public static function cases(): iterable
    {
        yield 'Jacobian conjecture session using dollar delimiters #1' => [
            '\\mathbb{C}^3 \\to \\mathbb{C}^3',
            'ℂ³ → ℂ³',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #2' => [
            '\\{3x+2y,\\; 27x^2-4z-1,\\; x(x-1)(x+1)\\} \\quad\\Rightarrow\\quad x \\in \\{0, \\pm 1\\},',
            '{3x+2y, 27x²-4z-1, x(x-1)(x+1)} ⇒ x ∈ {0, ± 1},',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #3' => [
            'F_1 = -\\frac{1}{4x^2}.',
            'F₁ = -1/(4x²).',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #4' => [
            '-2',
            '-2',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #5' => [
            '(0,0,-1/4)',
            '(0,0,-1/4)',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #6' => [
            '(1,-3/2,13/2)',
            '(1,-3/2,13/2)',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #7' => [
            '(1,1,1)',
            '(1,1,1)',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #8' => [
            '(2,1,0)',
            '(2,1,0)',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #9' => [
            '(-1/4, 0, 0)',
            '(-1/4, 0, 0)',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #10' => [
            '\\{(0,0,-1/4), (1,-3/2,13/2), (-1,3/2,13/2)\\}',
            '{(0,0,-1/4), (1,-3/2,13/2), (-1,3/2,13/2)}',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #11' => [
            '(2,1,1)',
            '(2,1,1)',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #12' => [
            '(7/3,-2/5,11/7)',
            '(7/3,-2/5,11/7)',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #13' => [
            '\\{y - p(x),\\; q(x)\\}',
            '{y - p(x), q(x)}',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #14' => [
            '\\deg q = 3',
            'deg q = 3',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #15' => [
            '[\\mathbb{C}(x,y,z):\\mathbb{C}(F_1,F_2,F_3)] = 3',
            '[ℂ(x,y,z):ℂ(F₁,F₂,F₃)] = 3',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #16' => [
            'u = 1+xy',
            'u = 1+xy',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #17' => [
            'G = u^2 z + y^2(4+3xy)',
            'G = u² z + y²(4+3xy)',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #18' => [
            'F_1 = uG',
            'F₁ = uG',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #19' => [
            'F_2 = y + 3xG',
            'F₂ = y + 3xG',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #20' => [
            'x=0',
            'x = 0',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #21' => [
            'F_2 = F_3 = 0',
            'F₂ = F₃ = 0',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #22' => [
            'xy = -3/2',
            'xy = -3/2',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #23' => [
            'x^2 z = 13/2',
            'x² z = 13/2',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #24' => [
            '\\mathbb{C}^*',
            'ℂ^*',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #25' => [
            's \\mapsto (s,\\, -\\tfrac{3}{2s},\\, \\tfrac{13}{2s^2})',
            's ↦ (s, -3/(2s), 13/(2s²))',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #26' => [
            'X',
            'X',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #27' => [
            'p_\\pm',
            'p_±',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #28' => [
            'F(-x,-y,z) = (F_1, -F_2, -F_3)',
            'F(-x,-y,z) = (F₁, -F₂, -F₃)',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #29' => [
            'p_0',
            'p₀',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #30' => [
            's \\to \\infty',
            's → ∞',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #31' => [
            '(0,0,0)',
            '(0,0,0)',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #32' => [
            '\\Rightarrow',
            '⇒',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #33' => [
            '\\ge 2',
            '≥ 2',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #34' => [
            '\\ge 3',
            '≥ 3',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #35' => [
            '1',
            '1',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #36' => [
            '\\mathrm{diag}(-1/2,1,1),\\quad F_{\\rm intrinsic}(\\lambda)',
            'diag(-1/2,1,1), F_intrinsic(λ)',
            false,
        ];
        yield 'Jacobian conjecture session using dollar delimiters #37' => [
            '4+3xy',
            '4+3xy',
            false,
        ];
        yield 'satellite calculation session using bracket delimiters #1' => [
            'E \\approx \\frac{0.1\\ \\text{lux}}{100\\ \\text{lm/W}} = 0.001\\ \\text{W/m}^2',
            'E ≈ (0.1 lux)/(100 lm/W) = 0.001 W/m²',
            false,
        ];
        yield 'satellite calculation session using bracket delimiters #2' => [
            '\\boxed{1\\ \\text{milliwatt per square metre}}',
            '[1 milliwatt per square metre]',
            false,
        ];
        yield 'satellite calculation session using bracket delimiters #3' => [
            '5\\ \\text{km}^2 = 5{,}000{,}000\\ \\text{m}^2',
            '5 km² = 5,000,000 m²',
            false,
        ];
        yield 'satellite calculation session using bracket delimiters #4' => [
            implode("\n", [
                'P_{\\text{light}} = 0.001 \\times 5{,}000{,}000',
                '= \\boxed{5{,}000\\ \\text{W}}',
            ]),
            'P_light = 0.001 × 5,000,000 = [5,000 W]',
            false,
        ];
        yield 'satellite calculation session using bracket delimiters #5' => [
            implode("\n", [
                'P_{\\text{electric}} = 5\\ \\text{kW} \\times 0.2',
                '= \\boxed{1\\ \\text{kW}}',
            ]),
            'P_electric = 5 kW × 0.2 = [1 kW]',
            false,
        ];
        yield 'satellite calculation session using bracket delimiters #6' => [
            '\\pi(2.5\\ \\text{km})^2 = 19.6\\ \\text{km}^2',
            'π(2.5 km)² = 19.6 km²',
            false,
        ];
        yield 'satellite calculation session using bracket delimiters #7' => [
            implode("\n", [
                '0.001\\ \\text{W/m}^2 \\times 19.6 \\times 10^6\\ \\text{m}^2',
                '\\approx \\boxed{20\\ \\text{kW optical}}',
            ]),
            '0.001 W/m² × 19.6 × 10⁶ m² ≈ [20 kW optical]',
            false,
        ];
        yield 'satellite calculation session using bracket delimiters #8' => [
            implode("\n", [
                '1\\ \\text{kW} \\times \\frac{1}{3600}\\ \\text{hour}',
                '= \\boxed{0.28\\ \\text{Wh}}',
            ]),
            '1 kW × 1/3600 hour = [0.28 Wh]',
            false,
        ];
        yield 'Jacobian conjecture sessions using parenthesis and bracket delimiters #1' => [
            '\\det\\!\\left(\\frac{\\partial(F_1,F_2,F_3)}{\\partial(x,y,z)}\\right)=-2.',
            'det((∂(F₁,F₂,F₃))/(∂(x,y,z))) = -2.',
            false,
        ];
        yield 'Jacobian conjecture sessions using parenthesis and bracket delimiters #2' => [
            implode("\n", [
                '\\begin{aligned}',
                'F(0,0,-\\tfrac14)&=(-\\tfrac14,0,0),\\\\',
                'F(1,-\\tfrac32,\\tfrac{13}2)&=(-\\tfrac14,0,0),\\\\',
                'F(-1,\\tfrac32,\\tfrac{13}2)&=(-\\tfrac14,0,0).',
                '\\end{aligned}',
            ]),
            implode("\n", [
                'F(0,0,-1/4) = (-1/4,0,0),',
                'F(1,-3/2,13/2) = (-1/4,0,0),',
                'F(-1,3/2,13/2) = (-1/4,0,0).',
            ]),
            false,
        ];
        yield 'Jacobian conjecture sessions using parenthesis and bracket delimiters #3' => [
            'F=(F_1,F_2,F_3)',
            'F = (F₁,F₂,F₃)',
            false,
        ];
        yield 'Jacobian conjecture sessions using parenthesis and bracket delimiters #4' => [
            'F',
            'F',
            false,
        ];
        yield 'Jacobian conjecture sessions using parenthesis and bracket delimiters #5' => [
            '3',
            '3',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #1' => [
            implode("\n", [
                'J = \\begin{pmatrix}',
                '\\frac{\\partial f_1}{\\partial x} & \\frac{\\partial f_1}{\\partial y} & \\frac{\\partial f_1}{\\partial z} \\\\',
                '\\frac{\\partial f_2}{\\partial x} & \\frac{\\partial f_2}{\\partial y} & \\frac{\\partial f_2}{\\partial z} \\\\',
                '\\frac{\\partial f_3}{\\partial x} & \\frac{\\partial f_3}{\\partial y} & \\frac{\\partial f_3}{\\partial z}',
                '\\end{pmatrix}',
            ]),
            implode("\n", [
                'J = ⎛ (∂ f₁)/(∂ x) │ (∂ f₁)/(∂ y) │ (∂ f₁)/(∂ z) ⎞',
                '    ⎜ (∂ f₂)/(∂ x) │ (∂ f₂)/(∂ y) │ (∂ f₂)/(∂ z) ⎟',
                '    ⎝ (∂ f₃)/(∂ x) │ (∂ f₃)/(∂ y) │ (∂ f₃)/(∂ z) ⎠',
            ]),
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #2' => [
            implode("\n", [
                '\\begin{aligned}',
                'f_1 &= (1+xy)^3 z + y^2(1+xy)(4+3xy) \\\\',
                'f_2 &= y + 3x(1+xy)^2 z + 3xy^2(4+3xy) \\\\',
                'f_3 &= 2x - 3x^2y - x^3z',
                '\\end{aligned}',
            ]),
            implode("\n", [
                'f₁ = (1+xy)³ z + y²(1+xy)(4+3xy)',
                'f₂ = y + 3x(1+xy)² z + 3xy²(4+3xy)',
                'f₃ = 2x - 3x²y - x³z',
            ]),
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #3' => [
            'x, y, z',
            'x, y, z',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #4' => [
            '(x, y, z)',
            '(x, y, z)',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #5' => [
            '(0,\\; 0,\\; -\\tfrac14)',
            '(0, 0, -1/4)',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #6' => [
            '(-\\tfrac14,\\; 0,\\; 0)',
            '(-1/4, 0, 0)',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #7' => [
            '(1,\\; -\\tfrac32,\\; \\tfrac{13}{2})',
            '(1, -3/2, 13/2)',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #8' => [
            '(-1,\\; \\tfrac32,\\; \\tfrac{13}{2})',
            '(-1, 3/2, 13/2)',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #9' => [
            '(-\\frac14, 0, 0)',
            '(-1/4, 0, 0)',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #10' => [
            'F: \\mathbb{C}^3 \\to \\mathbb{C}^3',
            'F: ℂ³ → ℂ³',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #11' => [
            'F(0,0,-\\tfrac14) = F(1,-\\tfrac32,\\tfrac{13}{2}) = F(-1,\\tfrac32,\\tfrac{13}{2}) = (-\\tfrac14, 0, 0)',
            'F(0,0,-1/4) = F(1,-3/2,13/2) = F(-1,3/2,13/2) = (-1/4, 0, 0)',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #12' => [
            '\\mathbb{C}^3',
            'ℂ³',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #13' => [
            implode("\n", [
                '\\begin{aligned}',
                'f_1 &= \\frac{f_1^{\\text{ut}}(u,t)}{x^2}, \\quad',
                'f_2 = \\frac{f_2^{\\text{ut}}(u,t)}{x}, \\quad',
                'f_3 = x\\,(2 - 3u - t)',
                '\\end{aligned}',
            ]),
            'f₁ = (f₁ᵘᵗ(u,t))/(x²), f₂ = (f₂ᵘᵗ(u,t))/x, f₃ = x (2 - 3u - t)',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #14' => [
            '\\det J_F',
            'det J_F',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #15' => [
            '(-\\tfrac14, 0, 0)',
            '(-1/4, 0, 0)',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #16' => [
            'u = xy',
            'u = xy',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #17' => [
            't = x^2z',
            't = x²z',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #18' => [
            'x \\neq 0',
            'x ≠ 0',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #19' => [
            'f_1^{\\text{ut}}, f_2^{\\text{ut}}',
            'f₁ᵘᵗ, f₂ᵘᵗ',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #20' => [
            'u,t',
            'u,t',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #21' => [
            'x',
            'x',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #22' => [
            'x, x^2',
            'x, x²',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #23' => [
            '\\mathbb{C}^n \\to \\mathbb{C}^n',
            'ℂⁿ → ℂⁿ',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #24' => [
            'n \\geq 2',
            'n ≥ 2',
            false,
        ];
        yield 'Jacobian matrix session using dollar delimiters #25' => [
            '\\mathbb{P}^3',
            'ℙ³',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #1' => [
            'e^{i\\pi}+1=0',
            'e^(iπ)+1 = 0',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #2' => [
            implode("\n", [
                '\\boxed{',
                '\\mathcal{Z}(\\beta)',
                '=',
                '\\int_{\\mathcal M}',
                '\\exp\\!\\left(',
                '-\\beta\\left[',
                '\\frac12 g^{ij}(x)\\,\\partial_i\\phi\\,\\partial_j\\phi',
                '+V(\\phi)',
                '\\right]\\right)',
                '\\mathcal D\\phi',
                '}',
            ]),
            '[Z(β) = ∫_M exp( -β[ 1/2 gⁱʲ(x) ∂ᵢϕ ∂ⱼϕ +V(ϕ) ]) Dϕ]',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #3' => [
            implode("\n", [
                '\\begin{aligned}',
                '\\nabla_\\mu T^{\\mu\\nu}',
                '&=',
                '\\frac{1}{\\sqrt{-g}}',
                '\\partial_\\mu\\!\\left(\\sqrt{-g}\\,T^{\\mu\\nu}\\right)',
                '+\\Gamma^\\nu_{\\mu\\lambda}T^{\\mu\\lambda}',
                '=0, \\\\[4pt]',
                'R_{\\mu\\nu}-\\frac12 Rg_{\\mu\\nu}+\\Lambda g_{\\mu\\nu}',
                '&=',
                '\\frac{8\\pi G}{c^4}T_{\\mu\\nu}.',
                '\\end{aligned}',
            ]),
            implode("\n", [
                '∇_μ T^(μν) = 1/(√(-g)) ∂_μ(√(-g) T^(μν)) +Γ^ν_(μλ)T^(μλ) = 0,',
                'R_(μν)-1/2 Rg_(μν)+Λ g_(μν) = (8π G)/(c⁴)T_(μν).',
            ]),
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #4' => [
            implode("\n", [
                'f(z)',
                '=',
                '\\frac{1}{2\\pi i}',
                '\\oint_{\\gamma}',
                '\\frac{f(\\zeta)}{\\zeta-z}\\,d\\zeta,',
                '\\qquad',
                '\\det\\!\\begin{pmatrix}',
                '\\lambda-a & -b & 0\\\\',
                '-c & \\lambda-d & -e\\\\',
                '0 & -f & \\lambda-g',
                '\\end{pmatrix}',
                '=0.',
            ]),
            implode("\n", [
                'f(z) = 1/(2π i) ∮_γ (f(ζ))/(ζ-z) dζ, det⎛ λ-a │ -b  │ 0   ⎞ = 0.',
                '                                        ⎜ -c  │ λ-d │ -e  ⎟',
                '                                        ⎝ 0   │ -f  │ λ-g ⎠',
            ]),
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #5' => [
            implode("\n", [
                '\\Psi(x,t)=',
                '\\sum_{n=1}^{\\infty}',
                '\\underbrace{',
                'c_n',
                '\\sqrt{\\frac{2}{L}}',
                '\\sin\\!\\left(\\frac{n\\pi x}{L}\\right)',
                '}_{\\text{spatial eigenmode}}',
                '\\exp\\!\\left(-\\frac{i\\hbar n^2\\pi^2}{2mL^2}t\\right),',
                '\\qquad',
                '|\\Psi(x,t)|^2',
                '=',
                '\\begin{cases}',
                '\\Psi^\\ast\\Psi, & 0<x<L,\\\\',
                '0, & \\text{otherwise}.',
                '\\end{cases}',
            ]),
            implode("\n", [
                '                                                                                                 ⎧ Ψ^∗Ψ if 0 < x < L,',
                'Ψ(x,t) = ∑ₙ₌₁^∞ cₙ √(2/L) sin((nπ x)/L)_(spatial eigenmode) exp(-(iℏ n²π²)/(2mL²)t), |Ψ(x,t)|² = ⎨',
                '                                                                                                 ⎩ 0    otherwise.',
            ]),
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #6' => [
            'x=\\frac{-b\\pm\\sqrt{b^2-4ac}}{2a}',
            'x = (-b±√(b²-4ac))/(2a)',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #7' => [
            '\\int_0^\\infty e^{-x^2}\\,dx=\\frac{\\sqrt{\\pi}}{2}',
            '∫₀^∞ e^(-x²) dx = (√π)/2',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #8' => [
            'e^{i\\theta}=\\cos\\theta+i\\sin\\theta',
            'e^(iθ) = cos θ+i sin θ',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #9' => [
            '\\sum_{n=1}^{\\infty}\\frac{1}{n^2}=\\frac{\\pi^2}{6}',
            '∑ₙ₌₁^∞1/(n²) = π²/6',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #10' => [
            '\\lim_{x\\to 0}\\frac{\\sin x}{x}=1',
            'lim[x→0] (sin x)/x = 1',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #11' => [
            implode("\n", [
                '\\lim_{n\\to\\infty}',
                '\\left(1+\\frac{1}{n}\\right)^n=e',
            ]),
            'lim[n→∞] (1+1/n)ⁿ = e',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #12' => [
            implode("\n", [
                '\\int_0^1 \\frac{x^2}{1+x^3}\\,dx',
                '=\\frac{1}{3}\\ln 2',
            ]),
            '∫₀¹ x²/(1+x³) dx = 1/3 ln 2',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #13' => [
            implode("\n", [
                '\\sum_{k=1}^{n}\\frac{k}{k+1}',
                '=n+1-H_{n+1}',
            ]),
            '∑ₖ₌₁ⁿk/(k+1) = n+1-Hₙ₊₁',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #14' => [
            implode("\n", [
                '\\frac{',
                '  \\displaystyle \\frac{x^2+1}{x-1}',
                '  -',
                '  \\displaystyle \\frac{2x}{x+1}',
                '}{',
                '  \\displaystyle \\frac{x}{x^2-1}',
                '}',
            ]),
            '((x²+1)/(x-1) - 2x/(x+1))/(x/(x²-1))',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #15' => [
            implode("\n", [
                '\\lim_{x\\to 0}',
                '\\frac{',
                '  \\displaystyle \\frac{\\sin x}{x}-1',
                '}{',
                '  \\displaystyle \\frac{e^x-1}{x}-1',
                '}',
                '=0',
            ]),
            'lim[x→0] ((sin x)/x-1)/((eˣ-1)/x-1) = 0',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #16' => [
            implode("\n", [
                '\\frac{',
                '  1+\\displaystyle\\frac{1}{1+\\frac{1}{x}}',
                '}{',
                '  1-\\displaystyle\\frac{1}{1-\\frac{1}{x}}',
                '}',
            ]),
            '(1+1/(1+1/x))/(1-1/(1-1/x))',
            false,
        ];
        yield 'extended formulas from a renderer stress-test session #17' => [
            implode("\n", [
                '\\sum_{n=1}^{\\infty}',
                '\\frac{',
                '  \\displaystyle \\frac{1}{n}-\\frac{1}{n+1}',
                '}{',
                '  \\displaystyle 1+\\frac{1}{n^2}',
                '}',
            ]),
            '∑ₙ₌₁^∞ (1/n-1/(n+1))/(1+1/(n²))',
            false,
        ];
        yield 'renders common symbols, roots, sums, and integrals #1' => [
            '\\sum_{i=0}^n \\alpha_i + \\int_0^\\infty e^{-x^2}\\,dx = \\sqrt{\\pi}',
            '∑ᵢ₌₀ⁿ αᵢ + ∫₀^∞ e^(-x²) dx = √π',
            false,
        ];
        yield 'renders common accents and binomial notation #1' => [
            '\\binom{n}{k}+\\vec{x}+\\hat{y}+\\overline{AB}',
            '(n choose k)+x⃗+ŷ+overline(AB)',
            false,
        ];
        yield 'renders extended symbols and negated relations #1' => [
            '\\epsilon+\\varepsilon+\\varsigma+\\varkappa+\\oplus+\\otimes+\\therefore+\\because',
            'ϵ+ε+ς+ϰ+⊕+⊗+∴+∵',
            false,
        ];
        yield 'renders extended symbols and negated relations #2' => [
            'A\\not\\subseteq B,\\quad x\\not\\in X',
            'A ⊈ B, x ∉ X',
            false,
        ];
        yield 'renders relational algebra join operators #1' => [
            'R\\bowtie S,\\quad R\\Join S',
            'R ⋈ S, R ⋈ S',
            false,
        ];
        yield 'renders relational algebra join operators #2' => [
            'R\\ltimes S,\\quad R\\rtimes S',
            'R ⋉ S, R ⋊ S',
            false,
        ];
        yield 'renders relational algebra join operators #3' => [
            'R\\leftouterjoin S,\\quad R\\rightouterjoin S,\\quad R\\fullouterjoin S',
            'R ⟕ S, R ⟖ S, R ⟗ S',
            false,
        ];
        yield 'renders delimiter commands and invisible delimiters #1' => [
            '\\lvert{x}\\rvert+\\lVert{v}\\rVert+\\left.\\frac{dy}{dx}\\right|_{x=0}',
            '|x|+‖v‖+dy/(dx)|ₓ₌₀',
            false,
        ];
        yield 'renders delimiter commands and invisible delimiters #2' => [
            '\\left\\lbrace x \\middle| x>0 \\right\\rbrace',
            '{ x | x > 0 }',
            false,
        ];
        yield 'renders named, modular, overlaid, and underlaid operators #1' => [
            '\\operatorname*{arg\\,max}_{x\\in X} f(x)',
            'arg max[x∈X] f(x)',
            false,
        ];
        yield 'renders named, modular, overlaid, and underlaid operators #2' => [
            'a\\bmod n,\\quad a\\equiv b\\pmod n',
            'a mod n, a ≡ b (mod n)',
            false,
        ];
        yield 'renders named, modular, overlaid, and underlaid operators #3' => [
            '\\overset{!}{=}+\\underset{n}{x}+\\stackrel{def}{=}',
            '=^!+xₙ+=ᵈᵉᶠ',
            false,
        ];
        yield 'renders indexed roots and additional accents and wrappers #1' => [
            '\\sqrt[2]{x}+\\sqrt[3]{x}+\\sqrt[4]{x}+\\sqrt[n]{x}+\\sqrt[k]{x+1}',
            '√x+∛x+∜x+ⁿ√x+ᵏ√(x+1)',
            false,
        ];
        yield 'renders indexed roots and additional accents and wrappers #2' => [
            '\\acute{x}+\\grave{y}+\\widehat{xyz}+\\overrightarrow{AB}',
            'x́+ỳ+widehat(xyz)+overrightarrow(AB)',
            false,
        ];
        yield 'renders indexed roots and additional accents and wrappers #3' => [
            '\\textnormal{hello}+\\mbox{world}+\\boldsymbol{x}+{\\rm roman}+{\\bf bold}+{\\it italic}+{\\sf sans}+{\\tt mono}+{\\cal calligraphic}+{\\sl slanted}',
            'hello+world+x+roman+bold+italic+sans+mono+calligraphic+slanted',
            false,
        ];
        yield 'renders additional display environments #1' => [
            '\\begin{equation}\\begin{split}a&=b\\\\&=c\\end{split}\\end{equation}',
            implode("\n", [
                'a = b',
                '= c',
            ]),
            false,
        ];
        yield 'renders additional display environments #2' => [
            '\\begin{alignedat}{2}a&=b&\\quad c&=d\\\\e&=f&g&=h\\end{alignedat}',
            implode("\n", [
                'a = b c = d',
                'e = f g = h',
            ]),
            false,
        ];
        yield 'uses natural case conditions and aligns matrix columns #1' => [
            'f(x)=\\begin{cases}a & x<0 \\\\ b & \\text{if }x=0 \\\\ c & \\text{otherwise}\\end{cases}',
            implode("\n", [
                '       ⎧ a if x < 0',
                'f(x) = ⎨ b if x = 0',
                '       ⎩ c otherwise',
            ]),
            false,
        ];
        yield 'uses natural case conditions and aligns matrix columns #2' => [
            '\\begin{pmatrix}1&200\\\\3000&4\\end{pmatrix}',
            implode("\n", [
                '⎛ 1    │ 200 ⎞',
                '⎝ 3000 │ 4   ⎠',
            ]),
            false,
        ];
        yield 'composes matrices with fractions and adjacent matrices #1' => [
            implode("\n", [
                'R\\left(\\frac{\\pi}{4}\\right)',
                '=',
                '\\begin{pmatrix}',
                '\\frac{\\sqrt{2}}{2} & -\\frac{\\sqrt{2}}{2}\\\\',
                '\\frac{\\sqrt{2}}{2} & \\frac{\\sqrt{2}}{2}',
                '\\end{pmatrix}.',
            ]),
            implode("\n", [
                '   π',
                'R( ─ ) = ⎛ (√2)/2 │ -(√2)/2 ⎞',
                '   4     ⎝ (√2)/2 │ (√2)/2  ⎠.',
            ]),
            true,
        ];
        yield 'composes matrices with fractions and adjacent matrices #2' => [
            implode("\n", [
                '\\mathbf w',
                '=',
                'R\\left(\\frac{\\pi}{4}\\right)',
                '\\begin{pmatrix}1\\\\0\\end{pmatrix}',
                '=',
                '\\begin{pmatrix}\\frac{\\sqrt{2}}{2}\\\\\\frac{\\sqrt{2}}{2}\\end{pmatrix}.',
            ]),
            implode("\n", [
                '       π',
                'w = R( ─ ) ⎛ 1 ⎞ = ⎛ (√2)/2 ⎞',
                '       4   ⎝ 0 ⎠   ⎝ (√2)/2 ⎠.',
            ]),
            true,
        ];
        yield 'composes matrices with fractions and adjacent matrices #3' => [
            'A\\mathbf e_1=\\begin{pmatrix}\\pi\\\\0\\end{pmatrix},\\qquad A\\mathbf e_2=\\begin{pmatrix}0\\\\\\frac{1}{\\pi}\\end{pmatrix}.',
            implode("\n", [
                'Ae₁ = ⎛ π ⎞, Ae₂ = ⎛ 0   ⎞',
                '      ⎝ 0 ⎠        ⎝ 1/π ⎠.',
            ]),
            true,
        ];
        yield 'composes matrices with fractions and adjacent matrices #4' => [
            '\\sum_{i=0}^n x_i=\\begin{pmatrix}a&b\\\\c&d\\end{pmatrix}.',
            implode("\n", [
                ' n',
                ' ∑  xᵢ = ⎛ a │ b ⎞',
                'i=0      ⎝ c │ d ⎠.',
            ]),
            true,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #1' => [
            'x=y',
            'x = y',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #2' => [
            'x =y',
            'x = y',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #3' => [
            implode("\n", [
                'x=',
                'y',
            ]),
            'x = y',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #4' => [
            implode("\n", [
                'x',
                '=',
                'y',
            ]),
            'x = y',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #5' => [
            'x_{i=0}',
            'xᵢ₌₀',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #6' => [
            'x\\neq0',
            'x ≠ 0',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #7' => [
            'A\\to B',
            'A → B',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #8' => [
            '\\pi\\cdot\\frac{1}{\\pi}',
            'π · 1/π',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #9' => [
            '\\sin\\theta',
            'sin θ',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #10' => [
            '\\sin^2 x',
            'sin² x',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #11' => [
            '-\\sin\\theta',
            '-sin θ',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #12' => [
            'i\\sin\\theta',
            'i sin θ',
            false,
        ];
        yield 'normalizes relation, multiplication, and named-operator spacing #13' => [
            '\\det(A)',
            'det(A)',
            false,
        ];
        yield 'treats a backslash followed by a line ending as control space #1' => [
            implode("\n", [
                '\\boxed{',
                '(1,1,1),\\ (1,1,2),\\ (1,2,5),\\ (1,5,13),\\ (2,5,29),\\',
                '(1,13,34),\\ (1,34,89)',
                '}.',
            ]),
            '[(1,1,1), (1,1,2), (1,2,5), (1,5,13), (2,5,29), (1,13,34), (1,34,89)].',
            true,
        ];
        yield 'treats a backslash followed by a line ending as control space #2' => [
            implode("\n", [
                'a\\',
                'b',
            ]),
            'a b',
            false,
        ];
        yield 'stacks operator limits in display mode #1' => [
            '\\sum_{i=0}^n x_i',
            implode("\n", [
                ' n',
                ' ∑  xᵢ',
                'i=0',
            ]),
            true,
        ];
        yield 'stacks operator limits in display mode #2' => [
            '\\min_{x\\in X} f(x)',
            implode("\n", [
                'min f(x)',
                'x∈X',
            ]),
            true,
        ];
        yield 'stacks operator limits in display mode #3' => [
            '\\operatorname*{arg\\,max}_{x\\in X} f(x)',
            implode("\n", [
                'arg max f(x)',
                '  x∈X',
            ]),
            true,
        ];
        yield 'stacks operator limits in display mode #4' => [
            '\\int\\nolimits_0^1 f(x)\\,dx',
            '∫₀¹ f(x) dx',
            true,
        ];
        yield 'stacks operator limits in display mode #5' => [
            '\\int\\limits_0^1 f(x)\\,dx',
            implode("\n", [
                '1',
                '∫ f(x) dx',
                '0',
            ]),
            true,
        ];
        yield 'centers even case rows around a middle brace #1' => [
            'f(x) = \\begin{cases} x^{2} & x \\geq 0 \\\\ -x & x < 0 \\end{cases}',
            implode("\n", [
                '       ⎧ x² if x ≥ 0',
                'f(x) = ⎨',
                '       ⎩ -x if x < 0',
            ]),
            false,
        ];
        yield 'stacks fractions in display mode #1' => [
            'x=\\frac{-b\\pm\\sqrt{b^2-4ac}}{2a}',
            implode("\n", [
                '    -b±√(b²-4ac)',
                'x = ────────────',
                '         2a',
            ]),
            true,
        ];
        yield 'stacks fractions in display mode #2' => [
            '\\frac{x^2+1}{x-1}',
            implode("\n", [
                'x²+1',
                '────',
                'x-1',
            ]),
            true,
        ];
        yield 'stacks fractions in display mode #3' => [
            implode("\n", [
                '\\frac{1}',
                '{2}',
            ]),
            implode("\n", [
                '1',
                '─',
                '2',
            ]),
            true,
        ];
        yield 'keeps nested display fractions linear #1' => [
            '\\frac{\\frac{x^2+1}{x-1}-\\frac{2x}{x+1}}{\\frac{x}{x^2-1}}',
            implode("\n", [
                '(x²+1)/(x-1)-2x/(x+1)',
                '─────────────────────',
                '      x/(x²-1)',
            ]),
            true,
        ];
        yield 'keeps nested display fractions linear #2' => [
            '\\lim_{x\\to 0}\\frac{\\frac{\\sin x}{x}-1}{\\frac{e^x-1}{x}-1}=0',
            implode("\n", [
                '     (sin x)/x-1',
                'lim  ─────────── = 0',
                'x→0  (eˣ-1)/x-1',
            ]),
            true,
        ];
        yield 'keeps nested display fractions linear #3' => [
            '\\frac{1+\\frac{1}{1+\\frac{1}{x}}}{1-\\frac{1}{1-\\frac{1}{x}}}',
            implode("\n", [
                '1+1/(1+1/x)',
                '───────────',
                '1-1/(1-1/x)',
            ]),
            true,
        ];
        yield 'lays out unsupported and nested scripts while keeping script fractions linear #1' => [
            '\\partial_tU_2(t,0)=Aj_*(1-t)^{-A-1}.\\qquad x^{n^2}+x_{i_j}',
            implode("\n", [
                '                            2',
                '                    -A-1   n',
                '∂ₜU₂(t,0) = Aj (1-t)    . x  +x',
                '              *                i',
                '                                j',
            ]),
            true,
        ];
        yield 'lays out unsupported and nested scripts while keeping script fractions linear #2' => [
            'e^{\\frac{1}{2}}+\\tfrac{1}{2}',
            'e^(1/2)+1/2',
            true,
        ];
        yield 'returns undefined for unsupported commands #1' => [
            'x + \\unknown{y}',
            null,
            false,
        ];
        yield 'returns undefined for malformed groups and environments #1' => [
            '\\frac{1}{x',
            null,
            false,
        ];
        yield 'returns undefined for malformed groups and environments #2' => [
            'x}',
            null,
            false,
        ];
        yield 'returns undefined for malformed groups and environments #3' => [
            '\\begin{matrix}1 & 2',
            null,
            false,
        ];
        yield 'returns undefined for malformed groups and environments #4' => [
            'x\\',
            null,
            false,
        ];
    }

    #[DataProvider('cases')]
    public function testRendersLikeUpstream(string $source, ?string $expected, bool $display): void
    {
        $this->assertSame($expected, Latex::render($source, $display));
    }
}
