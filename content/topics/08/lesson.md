# Loops

## Learning objectives

- Use for and range() for a counted repetition.
- Use while for repetition controlled by a changing condition.
- Trace counters and accumulators, including zero-iteration cases.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Repeating a block

A loop repeats its indented body. A `for` loop takes values, one at a time, from an iterable: a value that can provide a sequence of items. Start with `range()`, which provides a sequence of integers without requiring us to write each one. Lists and other collections come later.

```python
for number in range(3):
    print(number)
```

Expected output:

```text
0
1
2
```


`range(3)` supplies 0, 1 and 2. Its stop value is excluded. With two arguments, `range(start, stop)` starts at the specified integer. A third argument chooses the step. `range(2, 7, 2)` supplies 2, 4 and 6. A negative step counts down; a zero step is invalid.

## Accumulating a result

An accumulator begins with a suitable starting value and is updated on each iteration. Initialize it before the loop. If you reset it inside the body, you discard earlier work.

```python
total = 0
for number in range(1, 4):
    total += number
print(total)
```

Expected output:

```text
6
```


Read this as three iterations: add 1 to zero, add 2 to one, then add 3 to three. The final print is outside the loop, so it prints the final total once. If you put it inside, it would show each running total.

## Repeating while a condition is true

A `while` loop checks a condition before every iteration, including the first. Use it when a condition, rather than a fixed count, decides when to stop. The body must eventually make the condition false, unless another control mechanism stops the loop later.

```python
count = 1
while count <= 3:
    print(count)
    count += 1
```

Expected output:

```text
1
2
3
```


The counter starts at 1. After printing 3 it becomes 4; the next condition check is false. If the initial condition is already false, the body runs zero times. A for loop over an empty range also runs zero times.

## Scope and indentation

The same indentation rules as conditions apply: the colon starts a block; aligned indented lines belong to the loop. A name assigned in a loop remains available after the loop in the surrounding code. However, if the loop runs zero times, its body assigns nothing; do not assume its loop variable has received a value.

## Common mistakes

- Including the stop endpoint when tracing range().
- Forgetting to update a while counter, producing an endless loop.
- Resetting an accumulator inside its own loop.
- Using a step that moves away from the stop value, producing an empty range rather than the intended values.
- Using a float as a range argument; range() needs integer arguments in these examples.

## Recap

for is convenient when iterating known values; while repeats while a condition stays truthy. range() excludes its stop. Set up counters and accumulators before the loop, and trace how each iteration changes them.

## Optional practice outside Hunter System

Trace the running total for range(1, 5). Write down how a countdown from 3 to 1 changes its counter. Do not run an example that you know cannot terminate; local practice is optional and outside the application.
