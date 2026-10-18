# Python introduction

## Learning objectives

- Explain what Python is and read simple print calls.
- Recognize strings, comments, case sensitivity and indentation.

Python is a general-purpose programming language known for readable code. People use it for automation scripts, data analysis, web back ends and for learning to program.

## Your first program

```python
print("Hello, World!")
```

Expected output:

```text
Hello, World!
```

`print()` displays a value on the screen. Text values (strings) are written inside quotes. The quotes mark where the text starts and ends; they are not displayed.

## More about print()

- `print("Hi", "there")` prints several values separated by one space: `Hi there`.
- `print(2 + 3)` prints `5`, because `2 + 3` without quotes is a calculation.
- `print("2" + "3")` prints `23`, because `+` joins two strings.
- `print()` with nothing inside prints an empty line.
- `\n` inside a string starts a new line.

## Comments

Outside a string literal, `#` starts a comment that continues to the end of the line. A # inside quoted text remains part of the string. Comments are notes for people.

```python
# This line is ignored
print("Shown")  # this part is ignored too
```

Expected output:

```text
Shown
```

## Indentation

Python uses indentation (spaces at the start of a line) to group statements into blocks, for example the body of an `if` statement. Indenting a line for no reason causes an `IndentationError`, and Python does not run the program at all.

## Case sensitivity

Python is case-sensitive. `print` works; `Print` and `PRINT` are unknown names.

## Common mistakes

- Leaving text unquoted.
- Changing print to Print.
- Adding indentation where no block has begun.

## Recap

Python reads statements in order after checking syntax. Use print to display values, quotes for text and comments for notes.

## Optional practice

Predict a short example on paper. Any local Python practice is optional; Hunter System does not execute student code.
