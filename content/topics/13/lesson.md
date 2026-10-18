# Dictionaries

## Learning objectives

- Store and retrieve values by keys.
- Update entries and use get for optional keys.
- Iterate through keys and key-value pairs.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Keys describe values

A dictionary maps unique keys to values. Write pairs as `key: value` inside braces. Unlike a list index, a key can describe an item, such as a name or subject. Strings and integers are useful keys. Keys must be hashable; a mutable list cannot be a key. Values can repeat. `{}` creates an empty dictionary.

```python
student = {"name": "Ada", "coins": 10}
print(student["name"])
print(student.get("rank", "E"))
```

Expected output:

```text
Ada
E
```


`data[key]` retrieves a value and raises KeyError if the key is absent. `data.get(key, default)` returns the default when absent, without adding the key. If you omit the default, it returns None. Membership `key in data` checks keys, not values.

## Update and visit entries

Assigning `data[key] = value` adds a new key or replaces an existing key's value. Each key has one current value. `len(data)` counts keys. `pop(key)` removes a key and returns its value, raising KeyError if absent unless you supply a default.

```python
scores = {"math": 4}
scores["math"] = 6
scores["python"] = 8
print(len(scores))
print(scores["math"])
```

Expected output:

```text
2
6
```


`for key in data` visits keys. `data.items()` supplies key-value pairs, so `for key, value in data.items()` uses the unpacking from topic 12. Dictionaries preserve insertion order. Updating an existing key's value does not move it. Even though order is defined, use keys to express what you want rather than treating a dictionary as a numbered list.

```python
scores = {"a": 2, "b": 3}
for key, value in scores.items():
    print(key, value)
```

Expected output:

```text
a 2
b 3
```


## Common mistakes

- Using membership to search values without explicitly asking for data.values().
- Expecting get to insert its default.
- Repeating a key and expecting two entries instead of one replacement.
- Confusing an empty dictionary with an empty set.

## Recap

Dictionaries connect keys to values. Use indexing for required keys and get for optional keys; use items when both parts of each entry are needed.

## Optional practice

Plan a dictionary describing a book with title and page count. Predict the result of changing the count and requesting a missing author with get. No execution is available in the app.
