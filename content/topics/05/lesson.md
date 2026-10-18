# Arithmetic operators

## Learning objectives

- Use addition, subtraction, multiplication, division, floor division, remainder and powers.
- Read operator precedence and use parentheses to express intent.
- Distinguish floor division from truncation, including negative operands.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Choosing an operation

Python uses `+` to add, `-` to subtract, `*` to multiply and `/` to divide numbers. For the ordinary int/float values in this lesson, `/` produces a float even when the answer has no fractional part. `**` means exponentiation: `2 ** 3` is two multiplied by itself three times. It is not written with `^`.

```python
print(8 + 2)
print(8 - 2)
print(8 * 2)
print(8 / 2)
print(2 ** 3)
```

Expected output:

```text
10
6
16
4.0
8
```


These operators do not change their operands. To keep a result, assign it to a name. The shorthand `total += 2`, introduced in topic 2, means to calculate an updated value and assign it back to total.

## Quotient and remainder

`//` is floor division: it rounds the mathematical quotient down toward negative infinity. `%` gives a remainder consistent with that quotient. With positive integers, these are useful for sharing items into equal groups and finding what is left over.

```python
items = 17
print(items // 5)
print(items % 5)
```

Expected output:

```text
3
2
```


With negative values, flooring differs from truncation toward zero. `-7 // 3` is -3, not -2. The corresponding remainder is 2, because `(-3) * 3 + 2` is -7. For a nonzero integer divisor b, the identity `a == (a // b) * b + (a % b)` holds. A nonzero remainder has the divisor's sign.

```python
print(-7 // 3)
print(-7 % 3)
```

Expected output:

```text
-3
2
```


## Which operation happens first?

Parentheses group an expression. Powers are evaluated before multiplication/division, which are evaluated before addition/subtraction. Operators such as * and / at the same precedence level are evaluated left to right. Powers group right to left, but simple one-power expressions are sufficient for this course. Prefer parentheses whenever your intent might be unclear.

```python
print(2 + 3 * 4)
print((2 + 3) * 4)
```

Expected output:

```text
14
20
```


## Common mistakes

- Using `^` for powers; use `**`.
- Expecting `/` to return int for an exact division of integers.
- Treating floor division as truncation toward zero for negative values.
- Dividing by zero with `/`, `//` or `%`; each raises `ZeroDivisionError` for the ordinary numbers used here.
- Assuming that `2 + 3 * 4` is evaluated strictly left to right without precedence.

## Recap

Choose the operator that matches the task. `/` gives a floating-point quotient, `//` floors, `%` gives the matching remainder and `**` raises a power. Parentheses communicate grouping clearly. Decimal floating-point arithmetic may be approximate, as discussed in topic 3.

## Optional practice outside Hunter System

On paper, share 23 items among groups of 5: calculate complete groups and leftover items. Compare `-8 // 3` with `int(-8 / 3)`. You may check these in a separate interpreter; the application does not execute code.
