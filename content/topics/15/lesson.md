# Modules and imports

## Learning objectives

- Import standard-library modules and selected names.
- Use aliases and module-qualified names.
- Recognize a local module and avoid name shadowing.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Reuse code from a module

A module groups Python definitions in a file. Python includes a standard library, so many common tasks need no third-party installation. `import math` binds the module name math. Access a function with a dot, such as `math.sqrt(9)`. This computes a square root; the result is a float.

```python
import math
print(math.sqrt(9))
```

Expected output:

```text
3.0
```


`from math import sqrt` binds sqrt directly in the current namespace. This does not bind the name math. `import math as m` gives the module a shorter local name m; use m.sqrt after that form. Choose one clear style and use the corresponding names consistently. `floor(x)` returns the greatest integer not above x; `ceil(x)` returns the least integer not below x. For example, floor(2.8) is 2 and ceil(2.2) is 3.

```python
from math import floor
import math as m
print(floor(2.8))
print(m.ceil(2.2))
```

Expected output:

```text
2
3
```


## Your own module

In a separate local Python project, a file named `helpers.py` can contain a function definition such as `def double(n):` with an indented `return n * 2`. Another file in that project can use `import helpers` and call `helpers.double(3)`. Do not name your own file math.py: it can shadow the standard module you intended to use. This is a local practice description, not an app upload or execution feature.

Module-level statements run when a module is first imported in an interpreter session. Repeated imports normally reuse the loaded module rather than rerunning every statement. A common guard, `if __name__ == "__main__":`, runs its body when the file is the main program rather than when imported. The special name is supplied by Python; it is not something you need to assign yourself. The next example imports factorial: for a positive whole number n, this multiplies the integers from 1 through n, so factorial(4) is 1 * 2 * 3 * 4.

```python
import math
print(math.factorial(4))
```

Expected output:

```text
24
```


## Common mistakes

- Calling sqrt without importing that name or qualifying it through math.
- Using math after importing it only as m.
- Naming a local file after a standard module.
- Using wildcard imports, which make it harder to know where names came from.

## Recap

Imports make reusable definitions available. The import form determines the names you can use. Keep module names clear and distinguish imported code from the main program.

## Optional practice

Plan a helpers.py file and a separate main file on paper. If you try them locally, keep them in a temporary study folder. No network access or package installation is needed.
