# Lists

## Learning objectives

- Read and change ordered list items.
- Distinguish append, extend and removal methods.
- Recognize shared lists and shallow copies.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## An ordered collection

A list holds items in order inside square brackets. Items may repeat. Indexing and slicing work like strings, but lists are mutable: item assignment changes the existing list. `len()` counts items and `in` tests membership.

```python
scores = [4, 6, 8]
scores[1] = 7
print(scores)
print(scores[:2])
```

Expected output:

```text
[4, 7, 8]
[4, 7]
```


## Add and remove items

`append(value)` adds one item at the end. `extend(other)` adds each item from another iterable. `insert(index, value)` inserts before an index. `pop(index)` removes and returns an item; without an index it removes the last. `remove(value)` removes the first matching value and raises ValueError if absent. Methods such as append and remove change the list and return None. Do not assign their result back to your list.

```python
items = [1]
items.append(2)
items.extend([3, 4])
last = items.pop()
print(items)
print(last)
```

Expected output:

```text
[1, 2, 3]
4
```


## Shared names and copies

Assignment makes a name refer to a value; it does not automatically copy a collection. After `other = scores`, both names refer to the same mutable list. A change through either name is visible through the other. Reassigning a name is different from changing an item. `scores.copy()` makes a shallow copy: a separate outer list. For the simple numbers here, changes to one outer list do not affect the other. Nested mutable items can still be shared; avoid assuming a shallow copy duplicates everything.

```python
a = [1, 2]
b = a
c = a.copy()
b.append(3)
print(a)
print(c)
```

Expected output:

```text
[1, 2, 3]
[1, 2]
```


Visit items with `for item in scores`. Avoid adding or removing items from the same list while iterating over it; build a new list when you need a filtered result.

## Common mistakes

- Using append when you want each item of another list added separately.
- Storing append's None return value over the list name.
- Confusing removal by value with removal by index.
- Assuming assignment makes an independent copy.

## Recap

Lists preserve order and allow changes. Choose methods according to whether you are adding one item, adding many, or removing by position or value.

## Optional practice

Plan a list of three study tasks. Predict its contents after append and pop. Practice is optional and runs only in your own Python environment.
