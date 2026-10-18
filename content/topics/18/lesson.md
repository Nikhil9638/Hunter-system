# Basic object-oriented programming

## Learning objectives

- Define classes and create instances.
- Initialize instance attributes with __init__.
- Call methods and distinguish instance and class attributes.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Group data with behavior

A class defines a kind of object. An instance is one particular object created from that class. Use `class Name:` followed by an indented body. Calling the class creates an instance. A method is a function defined in a class. Its first parameter is conventionally self, which refers to the instance receiving the call. When you call `object.method()`, Python supplies that instance automatically.

```python
class Greeter:
    def hello(self):
        return "Hello"

greeter = Greeter()
print(greeter.hello())
```

Expected output:

```text
Hello
```


## Initialize each instance

Python calls `__init__` to initialize a newly created instance. Other arguments supplied when calling the class are passed to this method after self. Assigning `self.name = name` stores an instance attribute; the parameter name alone is local to the method. Read an attribute with a dot, such as `student.name`. Each instance can have its own values.

```python
class Student:
    def __init__(self, name):
        self.name = name

a = Student("Ada")
b = Student("Lin")
print(a.name)
print(b.name)
```

Expected output:

```text
Ada
Lin
```


Methods can read and change instance attributes or return a calculated result. Do not pass self manually when calling a bound method on an object. An initializer should not return a non-None value; its job is to initialize, not to replace the new object.

## Shared class attributes

An attribute assigned directly in the class body belongs to the class. Instances can read it if they have not defined their own attribute with that name. Use immutable class attributes for shared facts, such as a category label. Keep per-instance mutable data inside __init__: a list assigned in the class body can be shared unexpectedly by all instances. Inheritance and advanced object models are beyond this introductory lesson.

```python
class Counter:
    category = "practice"
    def __init__(self):
        self.value = 0
    def add(self):
        self.value += 1

a = Counter()
b = Counter()
a.add()
print(a.value, b.value)
print(b.category)
```

Expected output:

```text
1 0
practice
```


## Common mistakes

- Omitting self from a method definition.
- Assigning only to a local variable when you intend to store an attribute.
- Calling a method without the instance or supplying self twice.
- Putting a per-student mutable list in the class body.

## Recap

Classes describe objects; instances hold individual state. Initialize attributes through self and use methods to work with that state. Keep shared class facts distinct from instance data.

## Optional practice

Design a Book class with title and pages, and a method that returns a short description. Trace two independent instances on paper or in local Python. The app runs MCQs, not student code.
