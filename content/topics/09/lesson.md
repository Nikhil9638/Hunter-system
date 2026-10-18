# Loop control

## Learning objectives

- Use break to stop a loop and continue to skip one iteration.
- Distinguish pass from loop control.
- Explain when a loop's else block runs.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Choose what happens next

`break` ends the nearest enclosing loop immediately. Statements below it in that iteration are skipped. Execution resumes after the loop. It does not end your whole program.

```python
for n in range(5):
    if n == 2:
        break
    print(n)
print("done")
```

Expected output:

```text
0
1
done
```


`continue` skips the rest of the current iteration and starts the next one. In a `while` loop, remember to update a counter before a possible continue; otherwise you might repeatedly skip the update.

```python
for n in range(4):
    if n == 1:
        continue
    print(n)
```

Expected output:

```text
0
2
3
```


## A placeholder and a completion check

`pass` does nothing. Use it as a temporary placeholder where Python requires an indented statement. It does not skip later statements. Replace placeholders with real work when needed.

A loop may have an `else` block aligned with `for` or `while`. It runs when the loop finishes normally, including when there are no iterations. It does not run if that loop exits through `break`. This is different from an if's else. In nested loops, break affects only the inner loop that contains it.

```python
for n in range(2):
    pass
else:
    print("finished")
```

Expected output:

```text
finished
```


## Common mistakes

- Using pass when you meant continue: subsequent statements still run.
- Expecting break to stop both an inner and outer loop.
- Aligning a loop's else with an if inside the body.
- Continuing before a while counter update and accidentally creating an endless loop.

## Recap

Break stops the nearest loop, continue skips one iteration, and pass does nothing. Loop else means normal completion without break.

## Optional practice

On paper, trace a loop that skips 2 and stops at 5. Try changing break to continue in a separate local Python installation; this app does not execute practice code.
