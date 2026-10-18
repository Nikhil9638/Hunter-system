# Tuples and sets

## Learning objectives

- Use immutable ordered tuples and unpacking.
- Use sets for unique membership and set operations.
- Avoid depending on set iteration order.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Tuples preserve positions

A tuple is an ordered sequence, often written with parentheses. A comma creates a tuple: `(7,)` has one item, while `(7)` is just the integer 7. Tuples allow duplicates and support indexing, slicing and len. Their positions cannot be reassigned. In these examples all items are immutable numbers or strings; an immutable tuple can still contain a mutable object, so immutability is not a guarantee about every nested value.

Unpacking assigns each item to a corresponding name. The number of names must match the number of items for the simple unpacking shown here.

```python
point = (3, 5)
x, y = point
print(x, y)
print(len((7,)))
```

Expected output:

```text
3 5
1
```


## Sets describe membership

A set is an unordered collection of unique, hashable items. Numbers and strings work as elements; a mutable list does not. Write a nonempty set with braces, or create one with `set(iterable)`. Use `set()` for an empty set because `{}` creates an empty dictionary, introduced next.

`add(value)` adds an element. Adding an existing element changes nothing. `discard(value)` removes it if present and does not fail if absent. Do not use positions to access a set. Never depend on printed or iteration order. For deterministic display of comparable numbers, `sorted(values)` returns a sorted list.

```python
values = {3, 1, 3}
values.add(2)
print(len(values))
print(sorted(values))
```

Expected output:

```text
3
[1, 2, 3]
```


Union `a | b` contains elements from either set; intersection `a & b` contains only shared elements; difference `a - b` contains elements in a but not b. Difference is directional.

```python
a = {1, 2}
b = {2, 3}
print(sorted(a | b))
print(sorted(a & b))
print(sorted(a - b))
```

Expected output:

```text
[1, 2, 3]
[2]
[1]
```


## Common mistakes

- Forgetting the comma in a one-item tuple.
- Using {} when an empty set is needed.
- Expecting duplicate set elements or a stable position.
- Trying to insert a mutable list into a set.

## Recap

Tuples preserve ordered positions without item reassignment. Sets remove duplicates and support membership and mathematical set operations; order is not part of their meaning.

## Optional practice

Write a tuple for a two-dimensional point and sets for two students' interests. Predict their shared interests without guessing display order. Use local Python only if you wish.
