# Conditions

## Learning objectives

- Write if/elif/else branches with colons and consistent indentation.
- Predict which branch runs and distinguish a chain from separate if statements.
- Use nested conditions sparingly and order boundary checks correctly.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Choosing a path

An `if` statement runs an indented block only when its condition is truthy. A colon ends the header. Use four spaces for each indentation level in this course, and keep all lines in the same block aligned. Do not indent the next statement unless it belongs to that block.

```python
score = 3
if score >= 3:
    print("Pass")
print("Checked")
```

Expected output:

```text
Pass
Checked
```


`print("Checked")` is outside the block and runs whichever path was chosen. The comparison uses `>=`; changing it to `>` would exclude exactly 3.

## Two or more alternatives

`else` supplies a fallback when an if condition is false. It does not take its own condition. Use `elif` (meaning else-if) to test another condition only if earlier tests in the same chain were false. Python runs the first true branch in the chain, or else if none is true.

```python
points = 5
if points == 5:
    print("Full score")
elif points >= 3:
    print("Pass")
else:
    print("Try again")
```

Expected output:

```text
Full score
```


The strongest threshold is checked first. If `points >= 3` came first, it would also catch 5, and the later exact check would never be reached for that value. An else branch is optional; if no branch matches and there is no else, no branch body runs.

## Separate checks versus one chain

Two separate if statements are two independent decisions. Both may run. In an if/elif chain, at most one branch runs. Choose based on whether the actions are independent or mutually exclusive.

```python
number = 4
if number > 0:
    print("Positive")
if number % 2 == 0:
    print("Even")
```

Expected output:

```text
Positive
Even
```


## One decision inside another

A nested if is another decision inside a selected block. Its body needs one extra indentation level. Read it from the outside inward: the inner condition is tested only if the outer block is entered. When the rule is simply 'both checks must pass', an and expression may be clearer than deep nesting.

```python
ready = True
level = 2
if ready:
    if level >= 2:
        print("Begin")
```

Expected output:

```text
Begin
```


## Common mistakes

- Missing the colon after if, elif or else.
- Mixing inconsistent indentation or placing an else at the wrong level.
- Testing a broad threshold before a stricter threshold in one chain.
- Expecting a second independent if to behave as elif.
- Accidentally using assignment `=` instead of equality comparison `==` in a condition.

## Recap

Conditions select blocks. An if/elif/else chain picks its first matching branch. Independent if statements remain independent. Colons, aligned branches and deliberate boundary comparisons keep the logic clear.

## Optional practice outside Hunter System

On paper, choose three test values for a threshold: one below, exactly equal and one above it. Trace the branch each would take. Any local coding practice is optional and not run by this application.
