# Input and type conversion

## Learning objectives

- Explain that input() returns a string and that its prompt is printed.
- Convert suitable values with int(), float() and str().
- Recognize failed conversions and choose a deliberate conversion order.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Reading one line

`input()` reads one line of text from a person and returns a string without the line-ending newline. Its optional argument is a prompt printed before reading. In a separate terminal, the terminal may echo what you type; that echo is not output produced by the Python program.

```python
name = input("Name: ")
print("Hello", name)
```

Controlled input (one value per line):

```text
Ana
```

Expected output:

```text
Name: Hello Ana
```


The expected output shows the prompt immediately followed by the greeting because `input()` does not itself print a newline. With the controlled input, `name` holds `"Ana"`.

## Asking for a number

Typing digits does not make `input()` return an integer. Use `int(text)` for whole-number text, or `float(text)` for floating-point text. Read a value first, then convert it. Conversion returns a new value; it does not change the original string unless you assign the result.

```python
count = int(input())
print(count + 2)
```

Controlled input (one value per line):

```text
7
```

Expected output:

```text
9
```


Here the controlled input is `7`, so `count` becomes the integer 7 before addition. If you joined the input with another string instead, you would be joining characters rather than adding numbers.

## Conversion choices

`int("12")` produces 12. `float("12.5")` produces 12.5. `str(12)` produces `"12"`, useful when joining text. `int(3.9)` produces 3 by dropping the fractional part toward zero; `int(-3.9)` produces -3. This is truncation, not rounding.

```python
print(float("2.5"))
print("Level " + str(3))
print(int(-3.9))
```

Expected output:

```text
2.5
Level 3
-3
```


`int("3.9")` does not work: the text is not an integer literal. `int(float("3.9"))` performs two deliberate conversions and produces 3, but discards the fractional information. Only do this when discarding that information is actually wanted.

## Invalid input

Text such as `"apple"` cannot become an integer. A conversion raises `ValueError` when the value has unsuitable contents. Passing a value of an unsuitable kind, such as `int(None)`, raises `TypeError`. Topic 16 teaches how to handle exceptions. Until then, our exercises state which input is supplied; they do not claim every user entry is valid.

`bool("False")` is True because the string is nonempty; it does not parse a written Boolean. Do not use bool() to interpret a yes/no answer from text. Boolean conditions are covered next in topic 6.

## Common mistakes

- Adding a number directly to an input string.
- Assuming int() rounds a floating-point value to the nearest integer.
- Trying int() on decimal text without deciding whether losing its fraction is acceptable.
- Forgetting that input() consumes one line per call and that an input prompt is output too.

## Recap

Input is text first. Convert only values with the expected format, keep the returned result, and distinguish string joining from arithmetic. A failed conversion stops the current program unless it is handled later with exception handling.

## Optional practice outside Hunter System

Write down the types at each step of reading a person's age, converting it to int and adding one. Try controlled entries in a separate Python interpreter if available. Hunter System has no code-input or execution feature.
