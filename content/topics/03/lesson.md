# Basic data types

## Learning objectives

- Recognize integers, floating-point numbers, strings and Boolean values.
- Use type() to inspect a value and distinguish quoted digits from numbers.
- Explain None and why a new assignment can change the type of a name.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Values have types

A type describes the kind of value and the operations that make sense for it. An `int` is a whole number, such as `8`, `0` or `-3`. A `float` is a floating-point number, such as `2.5` or `4.0`. A `str` is text inside matching quotes. Python treats single and double quotes alike when they contain the same text.

A `bool` has only two values: `True` and `False`, with capital first letters. Booleans are useful for yes/no facts. Comparisons and conditions use them in later lessons; you do not need a condition to store one now.

```python
print(type(8))
print(type(2.5))
print(type("hello"))
print(type(True))
```

Expected output:

```text
<class 'int'>
<class 'float'>
<class 'str'>
<class 'bool'>
```


`type(value)` reports the value's type. Its printed form includes the word class and the type name, such as `<class 'int'>`. You do not need to define a class to inspect a value this way; classes are introduced in topic 18.

## Quoted digits are text

`12` is an integer; `"12"` is a string. Both may look alike when printed, but the quotes change their meaning. String `+` joins text, as in topic 1. Numeric `+` adds numbers. Python does not guess whether you want joining or addition when you mix them. Topic 4 explains explicit conversion.

```python
print(4 + 2)
print("4" + "2")
```

Expected output:

```text
6
42
```


## Names and special values

A variable is a name referring to a value, not a box permanently restricted to one type. Assigning `count = "many"` after `count = 3` makes that name refer to a string. The original integer has not turned into text; the name now refers to another value.

`None` represents the absence of a value. It is not the string `"None"`, zero, or an empty string. We will use it again with functions. Python's spelling is exactly `None`.

```python
value = 3
value = "many"
print(type(value))
print(None)
```

Expected output:

```text
<class 'str'>
None
```


## A note about decimals

Floating-point values approximate many decimal fractions. Do not expect every decimal calculation to behave like exact pencil-and-paper arithmetic. For now, use simple values and recognize that `4.0` is still a float, even though it represents a whole-number quantity. This course does not treat floats as exact money amounts.

## Common mistakes

- Writing `true`, `false` or `none` instead of the correctly capitalized Python values.
- Assuming that digits inside quotes are automatically numbers.
- Expecting `"4" + 2` to convert either value automatically; it raises `TypeError`.
- Calling every value with a decimal point an integer because its fractional part is zero.

## Recap

Types belong to values. Use integers for whole numbers, floats for floating-point numbers, strings for text and Booleans for yes/no values. `None` means no value. Assignment can make a name refer to a value of a different type.

## Optional practice outside Hunter System

On paper, label the types of `-2`, `"-2"`, `2.0`, `False` and `None`. If you have a separate local Python interpreter, print their types. This is optional practice, not an executable feature of this application.
