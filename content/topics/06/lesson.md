# Comparison and logical operators

## Learning objectives

- Compare values with ==, !=, <, <=, > and >=.
- Combine Boolean expressions with and, or and not.
- Recognize truthiness and short-circuit evaluation without confusing them with assignment.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Asking a yes/no question

A comparison checks a relationship between values and normally produces `True` or `False`. `==` checks equality; `!=` checks inequality. `<` and `>` mean strictly less/greater than. `<=` and `>=` include equality. These are different from the single `=` used for assignment.

```python
print(4 == 4)
print(4 != 4)
print(4 < 4)
print(4 <= 4)
```

Expected output:

```text
True
False
False
True
```


Compare compatible kinds of values. Numeric comparisons between an int and a float are valid. The string `"5"` is not the number 5. Ordering text against a number, such as `"5" < 6`, raises `TypeError`; convert deliberately when input represents a number.

## Combining conditions

Use `and` when both Boolean expressions must be true. Use `or` when at least one must be true. `not` reverses truth: not True is False and not False is True. Python's logical operators are words, not `&&`, `||` and `!`.

```python
level = 3
ready = True
print(level >= 2 and ready)
print(level < 2 or ready)
print(not ready)
```

Expected output:

```text
True
True
False
```


Comparisons bind more tightly than `not`, then `and`, then `or`. Parentheses help readers follow a combined rule. A comparison can also be chained: `1 <= level <= 5` means the level is at least 1 and at most 5.

```python
level = 5
print(1 <= level <= 5)
```

Expected output:

```text
True
```


## Truthiness and short-circuiting

Python can test a value's truthiness. Zero, None and empty strings are falsy. Nonzero numbers and nonempty strings are truthy. When we introduce collections, empty collections will be falsy too. The text `"False"` is nonempty, so it is truthy.

`and` stops as soon as a falsy left operand decides the result; `or` stops as soon as a truthy left operand decides it. This is short-circuit evaluation. Unlike comparisons and not, and/or return one of their operand values, which need not be a bool. Start with explicit Boolean comparisons before using this value-returning behavior.

```python
print(bool("False"))
number = 0
print(number != 0 and 10 / number > 1)
print("" or "guest")
```

Expected output:

```text
True
False
guest
```


The second line avoids dividing by zero because the left comparison is False. This guards that particular denominator; it is not a general replacement for exception handling.

## Common mistakes

- Using `=` when equality testing needs `==`.
- Using `or` when both checks are required.
- Assuming and/or always return Boolean objects for non-Boolean operands.
- Treating a nonempty yes/no string as a parsed Boolean answer.
- Ordering a numeric input string against a number without conversion.

## Recap

Comparisons express relationships. Logical operators combine or reverse tests and may skip unnecessary evaluation. Use parentheses for a readable rule, and distinguish Boolean results from the operand values returned by and/or.

## Optional practice outside Hunter System

Write a Boolean expression that accepts levels 2 through 4, including both ends. Make a truth table for two Boolean values combined with and and or. Optional local interpreter checks are separate from Hunter System.
