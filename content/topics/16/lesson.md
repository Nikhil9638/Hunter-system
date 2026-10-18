# Exceptions

## Learning objectives

- Handle expected exceptions with try and except.
- Distinguish else and finally.
- Raise an appropriate exception for invalid input.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Respond to a failed operation

An exception interrupts normal execution. A syntax error prevents code from being parsed; a runtime exception occurs when an operation fails while running. Put a risky operation inside try, followed by an except naming the expected exception. If that exception occurs, the remaining try statements are skipped and the matching handler runs. Execution can continue after the handler.

```python
try:
    number = int("bad")
    print(number)
except ValueError:
    print("Use digits")
print("done")
```

Expected output:

```text
Use digits
done
```


Catch specific exceptions so unrelated programming mistakes are not silently hidden. ValueError can mean text has the wrong numeric format; TypeError means an operation received an unsuitable type; ZeroDivisionError means division by zero. An unmatched exception propagates to the caller and can stop the program.

## Success and cleanup

A try statement can have else after its handlers. Else runs only if the try body finishes without an exception. Finally runs when leaving the try statement, whether its body succeeded or raised an exception. In normal beginner code it is useful for cleanup. Do not return from finally: it can hide a result or exception. A with block, taught next, handles file closure more clearly.

```python
try:
    print(int("4"))
except ValueError:
    print("invalid")
else:
    print("success")
finally:
    print("finished")
```

Expected output:

```text
4
success
finished
```


## Report invalid input deliberately

`raise ValueError("message")` creates an explicit failure. This helps functions refuse values they cannot meaningfully process. Error messages should be useful and should not reveal secrets. `except ValueError as error` binds the exception object; `str(error)` gives its message. Catch only when you can respond meaningfully instead of pretending the operation succeeded.

```python
def check_age(age):
    if age < 0:
        raise ValueError("age must not be negative")
    return age

try:
    check_age(-1)
except ValueError as error:
    print(str(error))
```

Expected output:

```text
age must not be negative
```


## Common mistakes

- Using a broad bare except to conceal every failure.
- Expecting statements after a failed operation inside try to run.
- Confusing else, which requires success, with finally, which handles leaving either way.
- Catching the wrong exception class.

## Recap

Try an operation, handle specific failures, use else for success-only work and finally for cleanup. Raise an exception when a function must reject invalid input.

## Optional practice

Trace conversion of "12" and "twelve" through a try/except on paper. This is optional practice, not code executed by Hunter System.
