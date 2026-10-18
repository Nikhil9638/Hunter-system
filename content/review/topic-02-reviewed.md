# Variables and assignment

## Learning objectives

- Assign, update and name variables.
- Trace several assignments, swaps and independent reassignment.

A **variable** is a name that refers to a value.

```python
count = 0
name = "Ana"
```

Expected result: no printed output.

The `=` sign is the **assignment operator**: it stores the value on the right under the name on the left. (Checking equality uses `==`, which comes in a later topic.)

## Updating a value

```python
x = 5
x = x + 2   # uses the old value 5, then stores 7
```

Expected result: no printed output.

For the numeric values used here, shorthand operators update the number: `x += 2`, `x -= 2`, `x *= 3`.

## Naming rules

- Use letters, digits and underscores: `total_score`, `item2`.
- A name cannot start with a digit (`2nd_place` is invalid).
- No hyphens or spaces (`my-score` is invalid).
- Keywords such as `class`, `if` and `for` cannot be names.
- Names are case-sensitive: `name` and `Name` are different variables.

## Several names at once

```python
x, y = 3, 4      # x is 3, y is 4
a, b = 1, 2      # give both names starting values
a, b = b, a      # now a is 2 and b is 1
print(a, b)
a = b = 0        # both names get 0
```

Expected output:

```text
2 1
```

## Reassignment does not change another name

```python
a = 10
b = a
a = 20
print(b)   # 10
```

Expected output:

```text
10
```

`b = a` makes b refer to the same current value as a. Reassigning a later does not change b. This is reassignment, not copying every object: mutable lists can be shared through two names, as topic 11 explains.

## Common mistakes

- Using a name before giving it a value raises a `NameError`.
- A variable can be reassigned to a value of a different type, for example from `4` to `"four"`. The latest assignment wins.

## Recap

Names refer to values. Evaluate the right side before assigning. Initialize both names before a swap, and distinguish reassignment from changes to a shared mutable object.

## Optional practice

Predict a short example on paper. Any local Python practice is optional; Hunter System does not execute student code.
