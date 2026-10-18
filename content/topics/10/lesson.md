# Strings

## Learning objectives

- Index and slice text safely.
- Use common string methods and formatted strings.
- Explain string immutability.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Read parts of text

A string is a sequence of characters. Indexing starts at zero. Negative indices count from the end: -1 is the last character. An index outside the string raises IndexError. `len(text)` counts characters, including spaces.

```python
word = "Python"
print(word[0], word[-1])
print(word[1:4])
```

Expected output:

```text
P n
yth
```


A slice `text[start:stop]` includes start but excludes stop. Omit start to begin at zero, or stop to continue to the end. Slices tolerate bounds beyond the length. `in` tests whether a substring occurs, with matching case.

## Create new text

Strings are immutable: you cannot replace a character by assigning to an index. Methods such as `upper()`, `lower()`, `strip()` and `replace(old, new)` return new strings. Strip removes leading and trailing whitespace, not spaces in the middle. Store the returned value if you need it later.

```python
name = "  Ada  "
clean = name.strip()
print(clean.upper())
print(clean)
```

Expected output:

```text
ADA
Ada
```


An f-string starts with `f` before the quote. Expressions inside braces are inserted into the new string. This is useful for readable messages without manually converting each number. `\n` represents a newline inside a string; it is not two printed characters.

```python
score = 8
print(f"Score: {score}")
```

Expected output:

```text
Score: 8
```


## Common mistakes

- Counting the first character as index 1.
- Including the stop position when tracing a slice.
- Calling upper without storing its result and expecting the original to change.
- Assigning to a string index rather than building a new string.

## Recap

Indexing selects one character; slicing selects text. String operations create new values. Methods and f-strings help transform and describe text.

## Optional practice

Predict the first and last characters of your name, then describe a slice that omits both. Practice locally if desired; the app offers MCQs rather than execution.
