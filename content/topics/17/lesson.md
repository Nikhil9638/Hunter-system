# File handling

## Learning objectives

- Read and write UTF-8 text with with blocks.
- Distinguish read, write and append modes.
- Handle missing files and understand the read position.

These examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.

## Text files and safe practice

A file stores data beyond a variable's lifetime. `open(path, mode, encoding="utf-8")` opens a text file. Mode "r" reads an existing file, "w" writes and truncates an existing file, and "a" appends at the end, creating the file if needed. Never experiment with write mode on a file you need to keep.

The working examples here use the standard-library `tempfile.TemporaryDirectory` context manager. It provides a fresh temporary folder and cleans it up afterwards. The variable folder holds its path; joining it with "/note.txt" forms a path inside that disposable folder. No real document is overwritten. These snippets are intended for optional local study only.

```python
import tempfile
with tempfile.TemporaryDirectory() as folder:
    path = folder + "/note.txt"
    with open(path, "w", encoding="utf-8") as handle:
        handle.write("Hello\n")
    with open(path, "r", encoding="utf-8") as handle:
        print(handle.read(), end="")
```

Expected output:

```text
Hello
```


## Close files reliably

`with open(...) as handle:` closes the file when the block ends, even if an exception occurs. `handle.write(text)` writes the supplied text and returns the number of characters written; it does not add a newline automatically. Include `"\n"` if you want a line break. `read()` reads from the current position to the end. A second read at the end returns an empty string. `readline()` reads one line, usually including its newline. Iterating a text file visits lines.

```python
import tempfile
with tempfile.TemporaryDirectory() as folder:
    path = folder + "/note.txt"
    with open(path, "w", encoding="utf-8") as handle:
        handle.write("A")
    with open(path, "a", encoding="utf-8") as handle:
        handle.write("B")
    with open(path, "r", encoding="utf-8") as handle:
        print(handle.read())
        print(repr(handle.read()))
```

Expected output:

```text
AB
''
```


`print(line, end="")` avoids adding an extra newline when a file line already contains one. `repr(text)` returns a diagnostic string that includes surrounding quotes and escapes; `repr("")` therefore displays as two quote marks, making an empty read visible. Specify an encoding for predictable text conversion. File paths depend on your environment; these lessons never ask you to predict a temporary folder's actual name.

## Handle a missing file

Opening a missing file for reading raises FileNotFoundError. Catch that specific exception when your program can give a useful response. A relative path is interpreted from the process's current working directory, which is not always the script's directory. Our quiz examples explicitly describe temporary fixture files and their contents.

## Common mistakes

- Using w when you intended to preserve existing text.
- Expecting write to add a newline automatically.
- Reading again without accounting for the current position.
- Leaving a file open rather than using with.

## Recap

Choose r, w or a deliberately, use UTF-8, and let with close each handle. File reads advance a position. Use disposable temporary files for practice.

## Optional practice

Plan a temporary study note containing two lines. Predict read and readline results before trying it locally. Do not use personal documents or credentials; Hunter System does not execute these prompts.
