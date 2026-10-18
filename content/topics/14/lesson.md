# Functions

## Learning objectives

- Define and call functions with parameters.
- Distinguish returned values from printed output.
- Use defaults and understand local names.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Name a reusable operation

`def` introduces a function name, parentheses and a colon. Its indented body runs when you call the function, not when the definition is first read. Parameters are names in the definition; arguments are values supplied by a call. Use descriptive names to make each function's purpose clear.

```python
def double(number):
    return number * 2

print(double(4))
```

Expected output:

```text
8
```


## Return a result

`return value` sends a value to the caller and ends that call immediately. Statements later in the same path are not reached. Printing displays text but does not return that text. A function with no return, or a bare return, returns None. Store a returned value or use it in another expression.

```python
def greet():
    print("Hello")

result = greet()
print(result)
```

Expected output:

```text
Hello
None
```


## Arguments and local names

Positional arguments match parameters in order. Keyword arguments name the parameter explicitly. A parameter can have a default, such as `name="friend"`; omitting that argument uses the default. In the ordinary parameter lists shown here, required positional parameters come before defaulted positional parameters. Use immutable defaults like numbers or strings here. A mutable default such as a list is created once at definition time and can accidentally share state between calls; avoid that pattern at this level.

Names assigned inside a function are local to that call unless you explicitly use more advanced scope features. A local assignment does not reassign an outer variable of the same name. Prefer parameters and return values instead of changing outside state.

```python
name = "Ada"
def greet(name="friend"):
    return f"Hi {name}"

print(greet())
print(greet(name="Lin"))
print(name)
```

Expected output:

```text
Hi friend
Hi Lin
Ada
```


## Common mistakes

- Forgetting parentheses when you intend to call a function.
- Expecting print to supply a useful returned result.
- Supplying too few required arguments or misspelling a keyword.
- Expecting a local variable to be available outside its function.

## Recap

Functions group reusable steps. Parameters receive arguments, return gives a result, and local names keep each call's work separate.

## Optional practice

Design a function that returns twice a number. Predict a call before checking it in local Python. Hunter System does not run submitted code.
