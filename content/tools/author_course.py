"""Original, reviewable course source. Generates private Markdown/JSON artifacts; runs no lesson code."""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOPICS = []

def example(code, stdout="", *, stdin="", files=None, exception=None):
    return dict(code=code, stdout=stdout, stdin=stdin, files=files or {}, exception=exception)

def concept(stem, right, wrong, explanation, objective, *, kind="concept"):
    return dict(kind=kind, stem=stem, code_snippet=None, answers=[right, *wrong], explanation=explanation, objective=objective)

def output(code, right, wrong, explanation, objective, *, context="", stdin="", files=None):
    q = concept((context + " What does this code print?").strip(), right, wrong, explanation, objective, kind="output")
    q.update(code_snippet=code, validation=example(code, right + "\n", stdin=stdin, files=files))
    return q

def error(code, right, wrong, explanation, objective, *, context="", stdin="", files=None):
    q = concept((context + " Which exception class stops this code?").strip(), right, wrong, explanation, objective, kind="mistake")
    q.update(code_snippet=code, validation=example(code, stdin=stdin, files=files, exception=right))
    return q

def write_topic(number, title, group, objectives, body, examples, questions, refs):
    assert len(questions) == 12
    preamble = f"# {title}\n\n## Learning objectives\n\n" + "\n".join("- " + x for x in objectives)
    preamble += "\n\nThese examples explain Python. Hunter System shows lessons and multiple-choice questions; it does not run your Python code.\n\n"
    for i, case in enumerate(examples, 1):
        block = "```python\n" + case["code"] + "\n```\n\n"
        if case["stdin"]:
            block += "Controlled input (one value per line):\n\n```text\n" + case["stdin"].rstrip("\n") + "\n```\n\n"
        if case["exception"]:
            block += f"Expected result: `{case['exception']}`. This is an intentional mistake, not a working solution.\n"
        elif case["stdout"]:
            block += "Expected output:\n\n```text\n" + case["stdout"].rstrip("\n") + "\n```\n"
        else:
            block += "Expected result: no printed output.\n"
        body = body.replace("{{EX" + str(i) + "}}", block)
    lesson = preamble + body.strip() + "\n"
    directory = ROOT / "topics" / f"{number:02d}"
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "lesson.md").write_text(lesson, encoding="utf-8", newline="\n")
    bank=[]
    for i, q in enumerate(questions, 1):
        answers=q.pop("answers")
        assert len(answers)==4 and len(set(answers))==4
        offset=(i+number)%4
        rotated=answers[-offset:]+answers[:-offset] if offset else answers
        q.update(key=f"py-{number:02d}-{i:02d}", topic_id=number, skill_group=group,
                 for_quiz=True, for_assessment=False, difficulty="medium" if q["kind"]!="concept" else "easy",
                 choices=[dict(label=chr(65+j),text=a,is_correct=a==answers[0]) for j,a in enumerate(rotated)])
        bank.append(q)
    (directory / "questions.json").write_text(json.dumps(bank, ensure_ascii=False, indent=2)+"\n", encoding="utf-8", newline="\n")
    (directory / "examples.json").write_text(json.dumps(examples, ensure_ascii=False, indent=2)+"\n", encoding="utf-8", newline="\n")
    TOPICS.append(dict(id=number,title=title,skill_group=group,objectives=objectives,sources=refs))

INTRO="https://docs.python.org/3.14/tutorial/introduction.html"
FLOW="https://docs.python.org/3.14/tutorial/controlflow.html"
DATA="https://docs.python.org/3.14/tutorial/datastructures.html"
BUILTINS="https://docs.python.org/3.14/library/functions.html"
TYPES="https://docs.python.org/3.14/library/stdtypes.html"
MODULES="https://docs.python.org/3.14/tutorial/modules.html"
ERRORS="https://docs.python.org/3.14/tutorial/errors.html"
IO="https://docs.python.org/3.14/tutorial/inputoutput.html"
CLASSES="https://docs.python.org/3.14/tutorial/classes.html"

write_topic(3,"Basic data types","G1",[
    "Recognize integers, floating-point numbers, strings and Boolean values.",
    "Use type() to inspect a value and distinguish quoted digits from numbers.",
    "Explain None and why a new assignment can change the type of a name."
],"""
## Values have types

A type describes the kind of value and the operations that make sense for it. An `int` is a whole number, such as `8`, `0` or `-3`. A `float` is a floating-point number, such as `2.5` or `4.0`. A `str` is text inside matching quotes. Python treats single and double quotes alike when they contain the same text.

A `bool` has only two values: `True` and `False`, with capital first letters. Booleans are useful for yes/no facts. Comparisons and conditions use them in later lessons; you do not need a condition to store one now.

{{EX1}}

`type(value)` reports the value's type. Its printed form includes the word class and the type name, such as `<class 'int'>`. You do not need to define a class to inspect a value this way; classes are introduced in topic 18.

## Quoted digits are text

`12` is an integer; `"12"` is a string. Both may look alike when printed, but the quotes change their meaning. String `+` joins text, as in topic 1. Numeric `+` adds numbers. Python does not guess whether you want joining or addition when you mix them. Topic 4 explains explicit conversion.

{{EX2}}

## Names and special values

A variable is a name referring to a value, not a box permanently restricted to one type. Assigning `count = "many"` after `count = 3` makes that name refer to a string. The original integer has not turned into text; the name now refers to another value.

`None` represents the absence of a value. It is not the string `"None"`, zero, or an empty string. We will use it again with functions. Python's spelling is exactly `None`.

{{EX3}}

## A note about decimals

Floating-point values approximate many decimal fractions. Do not expect every decimal calculation to behave like exact pencil-and-paper arithmetic. For now, use simple values and recognize that `4.0` is still a float, even though it represents a whole-number quantity. This course does not treat floats as exact money amounts.

## Common mistakes

- Writing `true`, `false` or `none` instead of the correctly capitalized Python values.
- Assuming that digits inside quotes are automatically numbers.
- Expecting `"4" + 2` to convert either value automatically; it raises `TypeError`.
- Calling every value with a decimal point an integer because its fractional part is zero.

## Recap

Types belong to values. Use integers for whole numbers, floats for floating-point numbers, strings for text and Booleans for yes/no values. `None` means no value. Assignment can make a name refer to a value of a different type.

## Optional practice outside Hunter System

On paper, label the types of `-2`, `"-2"`, `2.0`, `False` and `None`. If you have a separate local Python interpreter, print their types. This is optional practice, not an executable feature of this application.
""",[
    example('print(type(8))\nprint(type(2.5))\nprint(type("hello"))\nprint(type(True))',"<class 'int'>\n<class 'float'>\n<class 'str'>\n<class 'bool'>\n"),
    example('print(4 + 2)\nprint("4" + "2")',"6\n42\n"),
    example('value = 3\nvalue = "many"\nprint(type(value))\nprint(None)',"<class 'str'>\nNone\n")
],[
    concept("Which value is a float?","4.0",["4",'"4"',"False"],"A decimal-point numeric literal such as 4.0 is a float, even when its value is a whole number.","Recognize basic types"),
    concept("Which value is a Boolean?","True",['"True"',"1.5",'"yes"'],"True without quotes is one of the two bool values. Quoted text is a string.","Recognize basic types"),
    concept("What does None usually represent?","The absence of a value",["The integer zero","An empty string","A spelling of False"],"None is a distinct special value; it is not a number, text or Boolean.","Understand None"),
    concept("After x = 7 and then x = 'seven', what is true?","x now refers to a string",["x must still refer to an integer","Python changes every 7 in the program into text","The second assignment is always invalid"],"Reassignment changes what a name refers to. Names are not permanently tied to one value type.","Understand reassignment"),
    output('print(type(-5))',"<class 'int'>",["<class 'float'>","<class 'str'>","<class 'bool'>"],"-5 is an integer, so type() reports int.","Inspect types"),
    output('print(type("7"))',"<class 'str'>",["<class 'int'>","<class 'float'>","<class 'bool'>"],"Quotes make 7 text, so type() reports str.","Distinguish text from numbers"),
    output('print("7" + "2")',"72",["9","7 2","TypeError"],"Both operands are strings. + joins them without inserting a space.","Distinguish operations"),
    output('flag = False\nprint(type(flag))\nprint(flag)',"<class 'bool'>\nFalse",["<class 'str'>\nFalse","<class 'int'>\n0","<class 'bool'>\nfalse"],"False is a bool and print preserves the capital F.","Recognize Booleans"),
    output('item = 4\nitem = 4.0\nprint(type(item))',"<class 'float'>",["<class 'int'>","<class 'str'>","<class 'NoneType'>"],"The latest assigned value is 4.0, a float.","Understand reassignment"),
    error('print("4" + 2)',"TypeError",["ValueError","NameError","SyntaxError"],"String joining needs strings; numeric addition needs compatible numbers. Python does not convert this mixed pair automatically.","Identify mixed-type mistakes"),
    error('ready = true\nprint(ready)',"NameError",["TypeError","ValueError","SyntaxError"],"Lowercase true is read as an ordinary name. Since it was never assigned, it causes NameError. The Boolean spelling is True.","Use correct capitalization"),
    concept("A student says '12 and \"12\" have the same type because both contain digits.' What is the correction?","12 is int; quoted \"12\" is str",["Both are int","Both are float","Both are bool"],"The literal's syntax matters: quotes create text, not a numeric value.","Correct type confusion",kind="mistake")
],[INTRO,TYPES,BUILTINS])

write_topic(4,"Input and type conversion","G2",[
    "Explain that input() returns a string and that its prompt is printed.",
    "Convert suitable values with int(), float() and str().",
    "Recognize failed conversions and choose a deliberate conversion order."
],"""
## Reading one line

`input()` reads one line of text from a person and returns a string without the line-ending newline. Its optional argument is a prompt printed before reading. In a separate terminal, the terminal may echo what you type; that echo is not output produced by the Python program.

{{EX1}}

The expected output shows the prompt immediately followed by the greeting because `input()` does not itself print a newline. With the controlled input, `name` holds `"Ana"`.

## Asking for a number

Typing digits does not make `input()` return an integer. Use `int(text)` for whole-number text, or `float(text)` for floating-point text. Read a value first, then convert it. Conversion returns a new value; it does not change the original string unless you assign the result.

{{EX2}}

Here the controlled input is `7`, so `count` becomes the integer 7 before addition. If you joined the input with another string instead, you would be joining characters rather than adding numbers.

## Conversion choices

`int("12")` produces 12. `float("12.5")` produces 12.5. `str(12)` produces `"12"`, useful when joining text. `int(3.9)` produces 3 by dropping the fractional part toward zero; `int(-3.9)` produces -3. This is truncation, not rounding.

{{EX3}}

`int("3.9")` does not work: the text is not an integer literal. `int(float("3.9"))` performs two deliberate conversions and produces 3, but discards the fractional information. Only do this when discarding that information is actually wanted.

## Invalid input

Text such as `"apple"` cannot become an integer. A conversion raises `ValueError` when the value has unsuitable contents. Passing a value of an unsuitable kind, such as `int(None)`, raises `TypeError`. Topic 16 teaches how to handle exceptions. Until then, our exercises state which input is supplied; they do not claim every user entry is valid.

`bool("False")` is True because the string is nonempty; it does not parse a written Boolean. Do not use bool() to interpret a yes/no answer from text. Boolean conditions are covered next in topic 6.

## Common mistakes

- Adding a number directly to an input string.
- Assuming int() rounds a floating-point value to the nearest integer.
- Trying int() on decimal text without deciding whether losing its fraction is acceptable.
- Forgetting that input() consumes one line per call and that an input prompt is output too.

## Recap

Input is text first. Convert only values with the expected format, keep the returned result, and distinguish string joining from arithmetic. A failed conversion stops the current program unless it is handled later with exception handling.

## Optional practice outside Hunter System

Write down the types at each step of reading a person's age, converting it to int and adding one. Try controlled entries in a separate Python interpreter if available. Hunter System has no code-input or execution feature.
""",[
    example('name = input("Name: ")\nprint("Hello", name)',"Name: Hello Ana\n",stdin="Ana\n"),
    example('count = int(input())\nprint(count + 2)',"9\n",stdin="7\n"),
    example('print(float("2.5"))\nprint("Level " + str(3))\nprint(int(-3.9))',"2.5\nLevel 3\n-3\n")
],[
    concept("What type does input() return after the user types 25?","str",["int","float","bool"],"input() returns a line of text; digit characters are still text until explicitly converted.","Understand input type"),
    concept("Which conversion accepts the decimal text '2.5' directly?","float('2.5')",["int('2.5')","bool('2.5') returns 2.5","str(2.5) returns a float"],"float() parses suitable decimal text. int() does not directly parse text with a decimal point.","Select a conversion"),
    concept("What does int() do with a floating-point value such as -2.8?","Truncates toward zero",["Always rounds down","Always rounds up","Rounds to the nearest whole number"],"Converting a float to int drops the fractional part toward zero, so int(-2.8) is -2.","Understand truncation"),
    concept("Why use str(score) before joining score to a string with +?","To create text from the numeric value",["To permanently change every copy of score","To add one to score","To verify that score is positive"],"str() creates a text representation that can be concatenated with another string.","Convert for display"),
    output('value = input()\nprint(type(value))',"<class 'str'>",["<class 'int'>","<class 'float'>","<class 'bool'>"],"The controlled line contains digits, but input() still returns a str.","Inspect input",stdin="42\n",context="The controlled input is 42 followed by Enter."),
    output('n = int(input())\nprint(n + 1)',"9",["81","8","TypeError"],"int() converts the input text 8 into a number before adding one.","Convert integer input",stdin="8\n",context="The controlled input is 8 followed by Enter."),
    output('print(str(6) + "0")',"60",["6","0","TypeError"],"str(6) creates the string '6', and + joins the two strings.","Convert numbers to text"),
    output('print(int(3.8))\nprint(int(-3.8))',"3\n-3",["4\n-4","3\n-4","4\n-3"],"Both conversions truncate toward zero, rather than rounding or flooring.","Understand truncation"),
    output('print(float("4.5") + 0.5)',"5.0",["4.50.5","5","TypeError"],"float() parses 4.5; adding the exactly represented half produces float 5.0.","Convert decimal text"),
    error('print(int("3.5"))',"ValueError",["TypeError","NameError","SyntaxError"],"The string has decimal-point syntax, not valid integer text.","Recognize invalid integer text"),
    error('age = input()\nprint(age + 1)',"TypeError",["ValueError","NameError","SyntaxError"],"age is a string. Convert suitable text with int() before numeric addition.","Identify missing conversion",stdin="12\n",context="The controlled input is 12 followed by Enter."),
    concept("Why is bool('False') unsuitable for parsing a typed False answer?","A nonempty string is truthy regardless of its spelling",["It always raises SyntaxError","It always returns None","It changes the string into lowercase"],"bool() tests truthiness; it does not interpret the words 'True' and 'False'.", "Avoid Boolean parsing mistakes",kind="mistake")
],[BUILTINS,TYPES])

write_topic(5,"Arithmetic operators","G2",[
    "Use addition, subtraction, multiplication, division, floor division, remainder and powers.",
    "Read operator precedence and use parentheses to express intent.",
    "Distinguish floor division from truncation, including negative operands."
],"""
## Choosing an operation

Python uses `+` to add, `-` to subtract, `*` to multiply and `/` to divide numbers. For the ordinary int/float values in this lesson, `/` produces a float even when the answer has no fractional part. `**` means exponentiation: `2 ** 3` is two multiplied by itself three times. It is not written with `^`.

{{EX1}}

These operators do not change their operands. To keep a result, assign it to a name. The shorthand `total += 2`, introduced in topic 2, means to calculate an updated value and assign it back to total.

## Quotient and remainder

`//` is floor division: it rounds the mathematical quotient down toward negative infinity. `%` gives a remainder consistent with that quotient. With positive integers, these are useful for sharing items into equal groups and finding what is left over.

{{EX2}}

With negative values, flooring differs from truncation toward zero. `-7 // 3` is -3, not -2. The corresponding remainder is 2, because `(-3) * 3 + 2` is -7. For a nonzero integer divisor b, the identity `a == (a // b) * b + (a % b)` holds. A nonzero remainder has the divisor's sign.

{{EX3}}

## Which operation happens first?

Parentheses group an expression. Powers are evaluated before multiplication/division, which are evaluated before addition/subtraction. Operators such as * and / at the same precedence level are evaluated left to right. Powers group right to left, but simple one-power expressions are sufficient for this course. Prefer parentheses whenever your intent might be unclear.

{{EX4}}

## Common mistakes

- Using `^` for powers; use `**`.
- Expecting `/` to return int for an exact division of integers.
- Treating floor division as truncation toward zero for negative values.
- Dividing by zero with `/`, `//` or `%`; each raises `ZeroDivisionError` for the ordinary numbers used here.
- Assuming that `2 + 3 * 4` is evaluated strictly left to right without precedence.

## Recap

Choose the operator that matches the task. `/` gives a floating-point quotient, `//` floors, `%` gives the matching remainder and `**` raises a power. Parentheses communicate grouping clearly. Decimal floating-point arithmetic may be approximate, as discussed in topic 3.

## Optional practice outside Hunter System

On paper, share 23 items among groups of 5: calculate complete groups and leftover items. Compare `-8 // 3` with `int(-8 / 3)`. You may check these in a separate interpreter; the application does not execute code.
""",[
    example('print(8 + 2)\nprint(8 - 2)\nprint(8 * 2)\nprint(8 / 2)\nprint(2 ** 3)',"10\n6\n16\n4.0\n8\n"),
    example('items = 17\nprint(items // 5)\nprint(items % 5)',"3\n2\n"),
    example('print(-7 // 3)\nprint(-7 % 3)',"-3\n2\n"),
    example('print(2 + 3 * 4)\nprint((2 + 3) * 4)',"14\n20\n")
],[
    concept("Which operator raises a number to a power?","**",["^","//","%"],"Python uses ** for exponentiation. ^ is a different operator outside this lesson's scope.","Select arithmetic operators"),
    concept("For two ordinary integers, what kind of result does / produce?","float",["Always int","Always str","Always bool"],"True division of ordinary integers produces a floating-point result, even when the division is exact.","Understand division"),
    concept("Which operation finds leftovers after sharing a positive integer amount into equal positive-sized groups?","Remainder (%)",["Power (**)","Addition (+)","True division (/) alone"],"% gives the remainder after the corresponding floor division.","Use quotient and remainder"),
    concept("Why put parentheses around part of an arithmetic expression?","To make that grouping take precedence",["To convert all values into strings","To round every result","To make division by zero valid"],"Parentheses group an expression so it is evaluated as a unit before surrounding operations.","Use precedence"),
    output('print(7 // 2)\nprint(7 % 2)',"3\n1",["3.5\n1","3\n0","4\n-1"],"Seven contains three complete groups of two with one left over.","Use quotient and remainder"),
    output('print(6 / 3)',"2.0",["2","3","0"],"/ gives a float for these integer operands; print shows 2.0.","Understand division"),
    output('print(2 + 3 * 4)',"14",["20","24","9"],"Multiplication happens before addition: 3 * 4 is 12, then add 2.","Use precedence"),
    output('print((2 + 3) * 4)',"20",["14","24","9"],"The parenthesized sum is evaluated before multiplication.","Use parentheses"),
    output('print(-7 // 3)\nprint(-7 % 3)',"-3\n2",["-2\n-1","-2\n1","-3\n-2"],"Flooring -7/3 gives -3; the matching remainder is 2 because -3*3+2=-7.","Understand negative floor division"),
    error('print(8 / 0)',"ZeroDivisionError",["ValueError","TypeError","SyntaxError"],"True division by zero is undefined for these numbers and raises ZeroDivisionError.","Identify zero division"),
    error('print(8 % 0)',"ZeroDivisionError",["IndexError","NameError","SyntaxError"],"Remainder also needs a nonzero divisor.","Identify invalid remainder"),
    concept("A learner expects int(-7 / 3) and -7 // 3 to match. What was missed?","int() truncates toward zero; // floors toward negative infinity",["Both always round to nearest","// converts its operands to text","Negative division always raises an error"],"int(-7/3) is -2, whereas -7//3 is -3. They perform different operations.","Distinguish truncation and flooring",kind="mistake")
],[INTRO,TYPES])

write_topic(6,"Comparison and logical operators","G2",[
    "Compare values with ==, !=, <, <=, > and >=.",
    "Combine Boolean expressions with and, or and not.",
    "Recognize truthiness and short-circuit evaluation without confusing them with assignment."
],"""
## Asking a yes/no question

A comparison checks a relationship between values and normally produces `True` or `False`. `==` checks equality; `!=` checks inequality. `<` and `>` mean strictly less/greater than. `<=` and `>=` include equality. These are different from the single `=` used for assignment.

{{EX1}}

Compare compatible kinds of values. Numeric comparisons between an int and a float are valid. The string `"5"` is not the number 5. Ordering text against a number, such as `"5" < 6`, raises `TypeError`; convert deliberately when input represents a number.

## Combining conditions

Use `and` when both Boolean expressions must be true. Use `or` when at least one must be true. `not` reverses truth: not True is False and not False is True. Python's logical operators are words, not `&&`, `||` and `!`.

{{EX2}}

Comparisons bind more tightly than `not`, then `and`, then `or`. Parentheses help readers follow a combined rule. A comparison can also be chained: `1 <= level <= 5` means the level is at least 1 and at most 5.

{{EX3}}

## Truthiness and short-circuiting

Python can test a value's truthiness. Zero, None and empty strings are falsy. Nonzero numbers and nonempty strings are truthy. When we introduce collections, empty collections will be falsy too. The text `"False"` is nonempty, so it is truthy.

`and` stops as soon as a falsy left operand decides the result; `or` stops as soon as a truthy left operand decides it. This is short-circuit evaluation. Unlike comparisons and not, and/or return one of their operand values, which need not be a bool. Start with explicit Boolean comparisons before using this value-returning behavior.

{{EX4}}

The second line avoids dividing by zero because the left comparison is False. This guards that particular denominator; it is not a general replacement for exception handling.

## Common mistakes

- Using `=` when equality testing needs `==`.
- Using `or` when both checks are required.
- Assuming and/or always return Boolean objects for non-Boolean operands.
- Treating a nonempty yes/no string as a parsed Boolean answer.
- Ordering a numeric input string against a number without conversion.

## Recap

Comparisons express relationships. Logical operators combine or reverse tests and may skip unnecessary evaluation. Use parentheses for a readable rule, and distinguish Boolean results from the operand values returned by and/or.

## Optional practice outside Hunter System

Write a Boolean expression that accepts levels 2 through 4, including both ends. Make a truth table for two Boolean values combined with and and or. Optional local interpreter checks are separate from Hunter System.
""",[
    example('print(4 == 4)\nprint(4 != 4)\nprint(4 < 4)\nprint(4 <= 4)',"True\nFalse\nFalse\nTrue\n"),
    example('level = 3\nready = True\nprint(level >= 2 and ready)\nprint(level < 2 or ready)\nprint(not ready)',"True\nTrue\nFalse\n"),
    example('level = 5\nprint(1 <= level <= 5)',"True\n"),
    example('print(bool("False"))\nnumber = 0\nprint(number != 0 and 10 / number > 1)\nprint("" or "guest")',"True\nFalse\nguest\n")
],[
    concept("Which operator checks equality?","==",["=","!=",":="],"== compares equality. A single = assigns a value.","Distinguish equality from assignment"),
    concept("When is A and B true if A and B are Booleans?","Only when both are True",["When either one is True","Only when both are False","Whenever A and B differ"],"Both Boolean conditions must be true for and to be true.","Combine Boolean expressions"),
    concept("Which comparison includes the boundary value?","score >= 3",["score > 3","score < 3","score != 3"],">= includes equality, so a score of exactly 3 meets this lower bound.","Use inclusive comparisons"),
    concept("What do and/or return for general Python operands?","One of their operand values",["Always a bool","Always an int","Always None"],"and/or short-circuit and return the deciding operand. not always produces a bool.","Understand logical results"),
    output('print(5 > 2 and 5 < 8)',"True",["False","5","TypeError"],"Both comparisons are True, so the and expression is True.","Combine comparisons"),
    output('print(not (2 == 2))',"False",["True","2","None"],"2 == 2 is True; not reverses it.","Use not"),
    output('n = 0\nprint(n != 0 and 6 / n > 1)',"False",["True","ZeroDivisionError","0.0"],"The false left operand short-circuits and; 6/n is never evaluated.","Understand short-circuiting"),
    output('print("" or "guest")',"guest",["True","False","None"],"An empty string is falsy, so or evaluates and returns the second operand.","Understand truthiness"),
    output('print(2 <= 3 <= 3)',"True",["False","3","SyntaxError"],"Both 2<=3 and 3<=3 are true. Chained comparisons include both checks.","Use chained comparisons"),
    error('print("5" < 6)',"TypeError",["ValueError","SyntaxError","NameError"],"Python cannot order a string and an integer in this way; convert suitable input first.","Identify mixed-type comparisons"),
    error('print(True && False)',"SyntaxError",["TypeError","NameError","ValueError"],"&& is not Python syntax. The logical word is and.","Use Python logical syntax"),
    concept("A rule requires level >= 2 AND ready. Why is level >= 2 or ready wrong?","It accepts a case where only one requirement is met",["or always rejects every case","or changes level to 2","The ready variable becomes a string"],"or permits either requirement alone, whereas the stated rule requires both.","Choose the correct combination",kind="mistake")
],[TYPES,FLOW])

write_topic(7,"Conditions","G3",[
    "Write if/elif/else branches with colons and consistent indentation.",
    "Predict which branch runs and distinguish a chain from separate if statements.",
    "Use nested conditions sparingly and order boundary checks correctly."
],"""
## Choosing a path

An `if` statement runs an indented block only when its condition is truthy. A colon ends the header. Use four spaces for each indentation level in this course, and keep all lines in the same block aligned. Do not indent the next statement unless it belongs to that block.

{{EX1}}

`print("Checked")` is outside the block and runs whichever path was chosen. The comparison uses `>=`; changing it to `>` would exclude exactly 3.

## Two or more alternatives

`else` supplies a fallback when an if condition is false. It does not take its own condition. Use `elif` (meaning else-if) to test another condition only if earlier tests in the same chain were false. Python runs the first true branch in the chain, or else if none is true.

{{EX2}}

The strongest threshold is checked first. If `points >= 3` came first, it would also catch 5, and the later exact check would never be reached for that value. An else branch is optional; if no branch matches and there is no else, no branch body runs.

## Separate checks versus one chain

Two separate if statements are two independent decisions. Both may run. In an if/elif chain, at most one branch runs. Choose based on whether the actions are independent or mutually exclusive.

{{EX3}}

## One decision inside another

A nested if is another decision inside a selected block. Its body needs one extra indentation level. Read it from the outside inward: the inner condition is tested only if the outer block is entered. When the rule is simply 'both checks must pass', an and expression may be clearer than deep nesting.

{{EX4}}

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
""",[
    example('score = 3\nif score >= 3:\n    print("Pass")\nprint("Checked")',"Pass\nChecked\n"),
    example('points = 5\nif points == 5:\n    print("Full score")\nelif points >= 3:\n    print("Pass")\nelse:\n    print("Try again")',"Full score\n"),
    example('number = 4\nif number > 0:\n    print("Positive")\nif number % 2 == 0:\n    print("Even")',"Positive\nEven\n"),
    example('ready = True\nlevel = 2\nif ready:\n    if level >= 2:\n        print("Begin")',"Begin\n")
],[
    concept("What must end an if header before its indented body?","A colon",["A semicolon","A closing brace","The word then"],"Python uses a colon followed by indentation to introduce the body.","Write branch syntax"),
    concept("In one if/elif/else chain, which branch body runs?","The first matching branch, or else if none match",["Every branch with a true condition","Only the last elif","The else body always runs"],"Once one branch matches, later branches in that chain are skipped.","Trace a branch chain"),
    concept("When is else useful?","When a fallback action is needed if earlier tests fail",["When a variable must become a float","Only when there are exactly three conditions","When all branches must execute"],"else supplies the remaining path and has no condition of its own.","Use a fallback"),
    concept("Why test a score >= 90 before score >= 60 in a grading chain?","The broader >= 60 test would also catch 90 first",["Python always sorts thresholds automatically","elif cannot contain >=","The numbers would otherwise become strings"],"Branch order matters. A broad first test can prevent the stricter branch from being reached.","Order thresholds"),
    output('x = 2\nif x > 3:\n    print("High")\nelse:\n    print("Low")',"Low",["High","High\nLow","No output"],"2 > 3 is false, so only else runs.","Trace if/else"),
    output('x = 5\nif x > 0:\n    print("A")\nelif x > 3:\n    print("B")',"A",["B","A\nB","No output"],"The first test succeeds. Even though the second would also be true, elif is skipped.","Trace elif"),
    output('x = 5\nif x > 0:\n    print("A")\nif x > 3:\n    print("B")',"A\nB",["A","B","No output"],"These are separate if statements, so both true checks run.","Distinguish independent checks"),
    output('x = 0\nif x:\n    print("Inside")\nprint("Done")',"Done",["Inside","Inside\nDone","No output"],"Zero is falsy. The unindented final print runs regardless.","Use truthiness in branches"),
    output('ready = True\nlevel = 1\nif ready:\n    if level >= 2:\n        print("Start")\n    else:\n        print("Wait")',"Wait",["Start","Start\nWait","No output"],"The outer branch is entered, but the inner comparison is false, so its else prints Wait.","Trace nesting"),
    error('if True\n    print("Ready")',"SyntaxError",["TypeError","NameError","ValueError"],"The if header is missing its required colon.","Identify missing colon"),
    error('x = 1\nif x = 1:\n    print("One")',"SyntaxError",["NameError","TypeError","ZeroDivisionError"],"Ordinary assignment with = is not this condition syntax. Equality needs ==.","Identify assignment confusion"),
    concept("An else should belong to an inner if. Where must else align?","With that inner if header",["With the inner print line","Always at the left edge of the file","One level deeper than the inner body"],"Indentation determines which if an else belongs to; align it with the intended if.","Correct nested indentation",kind="mistake")
],[FLOW])

write_topic(8,"Loops","G3",[
    "Use for and range() for a counted repetition.",
    "Use while for repetition controlled by a changing condition.",
    "Trace counters and accumulators, including zero-iteration cases."
],"""
## Repeating a block

A loop repeats its indented body. A `for` loop takes values, one at a time, from an iterable: a value that can provide a sequence of items. Start with `range()`, which provides a sequence of integers without requiring us to write each one. Lists and other collections come later.

{{EX1}}

`range(3)` supplies 0, 1 and 2. Its stop value is excluded. With two arguments, `range(start, stop)` starts at the specified integer. A third argument chooses the step. `range(2, 7, 2)` supplies 2, 4 and 6. A negative step counts down; a zero step is invalid.

## Accumulating a result

An accumulator begins with a suitable starting value and is updated on each iteration. Initialize it before the loop. If you reset it inside the body, you discard earlier work.

{{EX2}}

Read this as three iterations: add 1 to zero, add 2 to one, then add 3 to three. The final print is outside the loop, so it prints the final total once. If you put it inside, it would show each running total.

## Repeating while a condition is true

A `while` loop checks a condition before every iteration, including the first. Use it when a condition, rather than a fixed count, decides when to stop. The body must eventually make the condition false, unless another control mechanism stops the loop later.

{{EX3}}

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
""",[
    example('for number in range(3):\n    print(number)',"0\n1\n2\n"),
    example('total = 0\nfor number in range(1, 4):\n    total += number\nprint(total)',"6\n"),
    example('count = 1\nwhile count <= 3:\n    print(count)\n    count += 1',"1\n2\n3\n")
],[
    concept("Which values does range(2, 5) supply?","2, 3, 4",["2, 3, 4, 5","0, 1, 2, 3, 4","3, 4, 5"],"The start is included; the stop is excluded.","Understand range endpoints"),
    concept("When does while test its condition?","Before each iteration, including the first",["Only after the first iteration","Only once before the whole loop","Only when the counter equals zero"],"A while body can run zero times if its initial condition is false.","Understand while"),
    concept("Where should a running total normally be initialized?","Before the loop",["After the loop","At the end of every iteration","Only inside print()"],"A single initial value lets later iterations retain earlier additions.","Use accumulators"),
    concept("Which loop is a natural choice for visiting each integer supplied by range(4)?","for",["if alone","else alone","A function definition alone"],"for takes one item from the iterable at a time.","Choose a loop"),
    output('for n in range(1, 4):\n    print(n)',"1\n2\n3",["0\n1\n2\n3","1\n2\n3\n4","2\n3"],"range begins at 1 and stops before 4.","Trace counted repetition"),
    output('total = 0\nfor n in range(3):\n    total += n\nprint(total)',"3",["6","2","0"],"The supplied values are 0, 1 and 2, which sum to 3.","Trace an accumulator"),
    output('n = 3\nwhile n > 0:\n    print(n)\n    n -= 1',"3\n2\n1",["3\n2\n1\n0","2\n1","1\n2\n3"],"The counter decreases after each print. At zero the next test is false.","Trace a countdown"),
    output('for n in range(5, 0, -2):\n    print(n)',"5\n3\n1",["5\n3\n1\n-1","0\n2\n4","5\n4\n3\n2\n1"],"A negative step moves from 5 toward but not through the excluded stop 0.","Use a negative step"),
    output('total = 7\nfor n in range(0):\n    total += 1\nprint(total)',"7",["8","0","NameError"],"range(0) is empty, so the initial total is unchanged.","Trace zero iterations"),
    error('for n in range(1, 5, 0):\n    print(n)',"ValueError",["TypeError","SyntaxError","IndexError"],"A zero step cannot advance a range and is rejected.","Identify invalid range step"),
    error('for n in range(2.5):\n    print(n)',"TypeError",["ValueError","NameError","ZeroDivisionError"],"A float is not a valid integer argument for this range call.","Identify invalid range type"),
    concept("A while loop uses n < 3 but never changes n from 0. What is the mistake?","The condition never becomes false",["The body runs exactly three times automatically","while changes n by itself","Zero is not a permitted counter value"],"while does not update counters for you. A terminating update or another stopping action is needed.","Avoid endless loops",kind="mistake")
],[FLOW,BUILTINS])

write_topic(9,"Loop control","G3",["Use break to stop a loop and continue to skip one iteration.","Distinguish pass from loop control.","Explain when a loop's else block runs."],"""
## Choose what happens next

`break` ends the nearest enclosing loop immediately. Statements below it in that iteration are skipped. Execution resumes after the loop. It does not end your whole program.

{{EX1}}

`continue` skips the rest of the current iteration and starts the next one. In a `while` loop, remember to update a counter before a possible continue; otherwise you might repeatedly skip the update.

{{EX2}}

## A placeholder and a completion check

`pass` does nothing. Use it as a temporary placeholder where Python requires an indented statement. It does not skip later statements. Replace placeholders with real work when needed.

A loop may have an `else` block aligned with `for` or `while`. It runs when the loop finishes normally, including when there are no iterations. It does not run if that loop exits through `break`. This is different from an if's else. In nested loops, break affects only the inner loop that contains it.

{{EX3}}

## Common mistakes

- Using pass when you meant continue: subsequent statements still run.
- Expecting break to stop both an inner and outer loop.
- Aligning a loop's else with an if inside the body.
- Continuing before a while counter update and accidentally creating an endless loop.

## Recap

Break stops the nearest loop, continue skips one iteration, and pass does nothing. Loop else means normal completion without break.

## Optional practice

On paper, trace a loop that skips 2 and stops at 5. Try changing break to continue in a separate local Python installation; this app does not execute practice code.
""",[example('for n in range(5):\n    if n == 2:\n        break\n    print(n)\nprint("done")','0\n1\ndone\n'),example('for n in range(4):\n    if n == 1:\n        continue\n    print(n)','0\n2\n3\n'),example('for n in range(2):\n    pass\nelse:\n    print("finished")','finished\n')],[
concept('Which loop does break stop?','The nearest enclosing loop',['Every loop in the program','Only a while loop','The next loop after this one'],'Break applies to its enclosing loop, regardless of for or while.','Understand break'),
concept('What does continue skip?','The remaining statements in the current iteration',['All future iterations','Only the next print anywhere','The whole program'],'The next iteration can still run.','Understand continue'),
concept('What does pass do?','Nothing',['Ends the loop','Skips the iteration','Returns a value'],'Pass is a placeholder statement.','Understand pass'),
concept('When does loop else run?','After normal completion without break',['Only after break','After every iteration','Only if the first item is false'],'Normal exhaustion, including an empty iterable, reaches else.','Understand loop else'),
output('for n in range(4):\n    if n == 2:\n        break\n    print(n)','0\n1',['0\n1\n2','0\n1\n2\n3','2'],'Break occurs before printing 2.','Trace break'),
output('for n in range(3):\n    if n == 1:\n        continue\n    print(n)','0\n2',['0\n1\n2','0','1'],'Only the iteration with 1 skips printing.','Trace continue'),
output('for n in range(2):\n    pass\n    print(n)','0\n1',['No output','1','0'],'Pass leaves the following print reachable.','Trace pass'),
output('for n in range(0):\n    print(n)\nelse:\n    print("empty")','empty',['0','No output','False'],'An empty range completes without break.','Trace empty loop else'),
output('for a in range(2):\n    for b in range(3):\n        print(a, b)\n        break','0 0\n1 0',['0 0','0 0\n0 1\n0 2','1 2'],'The inner loop stops once per outer iteration.','Trace nested break'),
error('break','SyntaxError',['NameError','TypeError','ValueError'],'Break is legal only inside a loop.','Identify misplaced break'),
error('continue','SyntaxError',['NameError','IndexError','ValueError'],'Continue requires an enclosing loop.','Identify misplaced continue'),
concept('A while counter update follows a continue that always runs. What is wrong?','The update is never reached',['Continue updates the counter automatically','The loop always runs once','Pass would also skip the update'],'Move the update before the continue or restructure the condition.','Avoid skipped updates',kind='mistake')
],[FLOW])

write_topic(10,"Strings","G4",["Index and slice text safely.","Use common string methods and formatted strings.","Explain string immutability."],"""
## Read parts of text

A string is a sequence of characters. Indexing starts at zero. Negative indices count from the end: -1 is the last character. An index outside the string raises IndexError. `len(text)` counts characters, including spaces.

{{EX1}}

A slice `text[start:stop]` includes start but excludes stop. Omit start to begin at zero, or stop to continue to the end. Slices tolerate bounds beyond the length. `in` tests whether a substring occurs, with matching case.

## Create new text

Strings are immutable: you cannot replace a character by assigning to an index. Methods such as `upper()`, `lower()`, `strip()` and `replace(old, new)` return new strings. Strip removes leading and trailing whitespace, not spaces in the middle. Store the returned value if you need it later.

{{EX2}}

An f-string starts with `f` before the quote. Expressions inside braces are inserted into the new string. This is useful for readable messages without manually converting each number. `\\n` represents a newline inside a string; it is not two printed characters.

{{EX3}}

## Common mistakes

- Counting the first character as index 1.
- Including the stop position when tracing a slice.
- Calling upper without storing its result and expecting the original to change.
- Assigning to a string index rather than building a new string.

## Recap

Indexing selects one character; slicing selects text. String operations create new values. Methods and f-strings help transform and describe text.

## Optional practice

Predict the first and last characters of your name, then describe a slice that omits both. Practice locally if desired; the app offers MCQs rather than execution.
""",[example('word = "Python"\nprint(word[0], word[-1])\nprint(word[1:4])','P n\nyth\n'),example('name = "  Ada  "\nclean = name.strip()\nprint(clean.upper())\nprint(clean)','ADA\nAda\n'),example('score = 8\nprint(f"Score: {score}")','Score: 8\n')],[
concept('What index selects the first character?','0',['1','-1','The length'],'Python sequences use zero-based indexing.','Use indices'),
concept('What does strip() remove by default?','Whitespace at both ends',['All spaces everywhere','Only the first character','All letters'],'Interior whitespace remains.','Use strip'),
concept('Why does upper() not change the original string?','Strings are immutable; upper returns a new string',['Upper works only on numbers','Upper returns nothing','Strings cannot contain capitals'],'Assign the returned string to retain it.','Understand immutability'),
concept('Which prefix enables expressions inside braces in an f-string?','f',['s','i','p'],'An f-string interpolates expressions inside braces.','Use f-strings'),
output('print("Hunter"[1:4])','unt',['Hun','unte','nter'],'Indices 1, 2 and 3 are included.','Trace slices'),
output('print("code"[-1])','e',['c','d','IndexError'],'Minus one selects the final character.','Trace negative indexing'),
output('text = "cat"\ntext.upper()\nprint(text)','cat',['CAT','None','Cat'],'The returned uppercase string is not assigned.','Trace immutable methods'),
output('print("banana".replace("a", "o"))','bonono',['banona','banana','bonana'],'Replace changes every matching occurrence by default in the returned string.','Use replace'),
output('n = 3\nprint(f"{n} coins")','3 coins',['{n} coins','n coins','coins 3'],'The expression n becomes its value.','Trace interpolation'),
error('print("hi"[2])','IndexError',['TypeError','ValueError','NameError'],'Valid indices are 0 and 1.','Identify invalid index'),
error('word = "cat"\nword[0] = "b"','TypeError',['IndexError','ValueError','SyntaxError'],'A string does not support item assignment.','Identify mutation error'),
concept('A student expects "abc"[0:2] to be "abc". Which correction helps?','The stop index is excluded',['The start index is excluded','Strings start at index 1','Slices always include the last character'],'The slice contains indices 0 and 1 only.','Avoid slice boundary mistakes',kind='mistake')
],[INTRO,TYPES])

write_topic(11,"Lists","G4",["Read and change ordered list items.","Distinguish append, extend and removal methods.","Recognize shared lists and shallow copies."],"""
## An ordered collection

A list holds items in order inside square brackets. Items may repeat. Indexing and slicing work like strings, but lists are mutable: item assignment changes the existing list. `len()` counts items and `in` tests membership.

{{EX1}}

## Add and remove items

`append(value)` adds one item at the end. `extend(other)` adds each item from another iterable. `insert(index, value)` inserts before an index. `pop(index)` removes and returns an item; without an index it removes the last. `remove(value)` removes the first matching value and raises ValueError if absent. Methods such as append and remove change the list and return None. Do not assign their result back to your list.

{{EX2}}

## Shared names and copies

Assignment makes a name refer to a value; it does not automatically copy a collection. After `other = scores`, both names refer to the same mutable list. A change through either name is visible through the other. Reassigning a name is different from changing an item. `scores.copy()` makes a shallow copy: a separate outer list. For the simple numbers here, changes to one outer list do not affect the other. Nested mutable items can still be shared; avoid assuming a shallow copy duplicates everything.

{{EX3}}

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
""",[example('scores = [4, 6, 8]\nscores[1] = 7\nprint(scores)\nprint(scores[:2])','[4, 7, 8]\n[4, 7]\n'),example('items = [1]\nitems.append(2)\nitems.extend([3, 4])\nlast = items.pop()\nprint(items)\nprint(last)','[1, 2, 3]\n4\n'),example('a = [1, 2]\nb = a\nc = a.copy()\nb.append(3)\nprint(a)\nprint(c)','[1, 2, 3]\n[1, 2]\n')],[
concept('What does append([2, 3]) add to a list?','One item that is itself a list',['Two separate integers','Only 2','Nothing'],'Append adds its argument as one item.','Distinguish additions'),
concept('What does pop() return on a nonempty list?','The removed last item',['The new list length','Always None','The first item without removing it'],'Without an index, pop removes the final item.','Use pop'),
concept('What does remove(4) mean?','Remove the first item equal to 4',['Remove index 4','Remove every 4','Remove four items'],'Remove uses value, not position.','Use remove'),
concept('What does a.copy() create for a list of integers?','A separate outer list with the same items',['An alias of the same outer list','An empty list','A sorted list'],'A shallow copy separates the outer container.','Understand copies'),
output('a = [1, 2]\na[0] = 9\nprint(a)','[9, 2]',['[1, 2]','[9, 1, 2]','[2, 9]'],'Item assignment replaces the value at index zero.','Trace mutation'),
output('a = [1]\na.extend([2, 3])\nprint(a)','[1, 2, 3]',['[1, [2, 3]]','[2, 3]','None'],'Extend adds each supplied item.','Trace extend'),
output('a = [3, 4, 3]\na.remove(3)\nprint(a)','[4, 3]',['[4]','[3, 4]','[3, 4, 3]'],'Only the first matching 3 is removed.','Trace remove'),
output('a = [1]\nb = a\nb.append(2)\nprint(a)','[1, 2]',['[1]','[2]','None'],'Both names refer to the same list.','Trace aliases'),
output('a = [1, 2]\nb = a.copy()\nb.pop()\nprint(a)','[1, 2]',['[1]','[2]','[]'],'Popping from the copied outer list leaves a intact.','Trace copies'),
error('print([][0])','IndexError',['ValueError','TypeError','KeyError'],'An empty list has no valid item index.','Identify empty access'),
error('a = [1, 2]\na.remove(3)','ValueError',['IndexError','TypeError','NameError'],'The requested value is absent.','Identify absent removal'),
concept('Why is items = items.append(5) a mistake?','Append returns None, which replaces the list reference',['Append sorts the list','Append cannot accept integers','Assignment copies the list'],'Call items.append(5) without assigning its return value.','Avoid method return confusion',kind='mistake')
],[DATA,TYPES])

write_topic(12,"Tuples and sets","G4",["Use immutable ordered tuples and unpacking.","Use sets for unique membership and set operations.","Avoid depending on set iteration order."],"""
## Tuples preserve positions

A tuple is an ordered sequence, often written with parentheses. A comma creates a tuple: `(7,)` has one item, while `(7)` is just the integer 7. Tuples allow duplicates and support indexing, slicing and len. Their positions cannot be reassigned. In these examples all items are immutable numbers or strings; an immutable tuple can still contain a mutable object, so immutability is not a guarantee about every nested value.

Unpacking assigns each item to a corresponding name. The number of names must match the number of items for the simple unpacking shown here.

{{EX1}}

## Sets describe membership

A set is an unordered collection of unique, hashable items. Numbers and strings work as elements; a mutable list does not. Write a nonempty set with braces, or create one with `set(iterable)`. Use `set()` for an empty set because `{}` creates an empty dictionary, introduced next.

`add(value)` adds an element. Adding an existing element changes nothing. `discard(value)` removes it if present and does not fail if absent. Do not use positions to access a set. Never depend on printed or iteration order. For deterministic display of comparable numbers, `sorted(values)` returns a sorted list.

{{EX2}}

Union `a | b` contains elements from either set; intersection `a & b` contains only shared elements; difference `a - b` contains elements in a but not b. Difference is directional.

{{EX3}}

## Common mistakes

- Forgetting the comma in a one-item tuple.
- Using {} when an empty set is needed.
- Expecting duplicate set elements or a stable position.
- Trying to insert a mutable list into a set.

## Recap

Tuples preserve ordered positions without item reassignment. Sets remove duplicates and support membership and mathematical set operations; order is not part of their meaning.

## Optional practice

Write a tuple for a two-dimensional point and sets for two students' interests. Predict their shared interests without guessing display order. Use local Python only if you wish.
""",[example('point = (3, 5)\nx, y = point\nprint(x, y)\nprint(len((7,)))','3 5\n1\n'),example('values = {3, 1, 3}\nvalues.add(2)\nprint(len(values))\nprint(sorted(values))','3\n[1, 2, 3]\n'),example('a = {1, 2}\nb = {2, 3}\nprint(sorted(a | b))\nprint(sorted(a & b))\nprint(sorted(a - b))','[1, 2, 3]\n[2]\n[1]\n')],[
concept('Which expression creates a one-item tuple?','(4,)',['(4)','[4]','{4}'],'The comma distinguishes the tuple from grouping.','Construct tuples'),
concept('Which expression creates an empty set?','set()',['{}','()','[]'],'Empty braces are an empty dictionary.','Construct sets'),
concept('What does a & b mean for sets?','Elements present in both sets',['Elements in either set','Elements only in a','Ordered concatenation'],'Intersection selects shared membership.','Use intersection'),
concept('Which property may you rely on for a set?','Its elements are unique',['Its first element has index zero','It preserves duplicates','Its printed order is fixed'],'Set membership is unique; positions are not defined.','Understand sets'),
output('point = (2, 8)\nx, y = point\nprint(y - x)','6',['10','-6','(2, 8)'],'Unpacking binds x to 2 and y to 8.','Trace unpacking'),
output('print(len({1, 1, 2, 2, 3}))','3',['5','2','1'],'Only three distinct integers remain.','Trace uniqueness'),
output('a = {1, 3}\na.add(3)\nprint(len(a))','2',['3','1','0'],'Adding an existing member leaves the size unchanged.','Trace set addition'),
output('print(sorted({1, 2} | {2, 4}))','[1, 2, 4]',['[1, 2, 2, 4]','[2]','[1, 4]'],'Union includes each distinct member once; sorted makes display deterministic.','Trace union'),
output('a = {1, 2, 3}\na.discard(9)\nprint(sorted(a - {2}))','[1, 3]',['[1, 2, 3]','[2]','KeyError'],'Discarding an absent item is harmless; difference excludes 2.','Trace discard and difference'),
error('point = (1, 2)\npoint[0] = 3','TypeError',['IndexError','ValueError','KeyError'],'Tuple positions cannot be reassigned.','Identify tuple mutation'),
error('x, y = (1, 2, 3)','ValueError',['TypeError','IndexError','NameError'],'Three items cannot fit two names in this unpacking.','Identify unpacking mismatch'),
error('values = {[1, 2]}','TypeError',['SyntaxError','ValueError','IndexError'],'A mutable list is unhashable and cannot be a set element.','Identify invalid element')
],[DATA,TYPES])

write_topic(13,"Dictionaries","G4",["Store and retrieve values by keys.","Update entries and use get for optional keys.","Iterate through keys and key-value pairs."],"""
## Keys describe values

A dictionary maps unique keys to values. Write pairs as `key: value` inside braces. Unlike a list index, a key can describe an item, such as a name or subject. Strings and integers are useful keys. Keys must be hashable; a mutable list cannot be a key. Values can repeat. `{}` creates an empty dictionary.

{{EX1}}

`data[key]` retrieves a value and raises KeyError if the key is absent. `data.get(key, default)` returns the default when absent, without adding the key. If you omit the default, it returns None. Membership `key in data` checks keys, not values.

## Update and visit entries

Assigning `data[key] = value` adds a new key or replaces an existing key's value. Each key has one current value. `len(data)` counts keys. `pop(key)` removes a key and returns its value, raising KeyError if absent unless you supply a default.

{{EX2}}

`for key in data` visits keys. `data.items()` supplies key-value pairs, so `for key, value in data.items()` uses the unpacking from topic 12. Dictionaries preserve insertion order. Updating an existing key's value does not move it. Even though order is defined, use keys to express what you want rather than treating a dictionary as a numbered list.

{{EX3}}

## Common mistakes

- Using membership to search values without explicitly asking for data.values().
- Expecting get to insert its default.
- Repeating a key and expecting two entries instead of one replacement.
- Confusing an empty dictionary with an empty set.

## Recap

Dictionaries connect keys to values. Use indexing for required keys and get for optional keys; use items when both parts of each entry are needed.

## Optional practice

Plan a dictionary describing a book with title and page count. Predict the result of changing the count and requesting a missing author with get. No execution is available in the app.
""",[example('student = {"name": "Ada", "coins": 10}\nprint(student["name"])\nprint(student.get("rank", "E"))','Ada\nE\n'),example('scores = {"math": 4}\nscores["math"] = 6\nscores["python"] = 8\nprint(len(scores))\nprint(scores["math"])','2\n6\n'),example('scores = {"a": 2, "b": 3}\nfor key, value in scores.items():\n    print(key, value)','a 2\nb 3\n')],[
concept('What does key in data test for a dictionary?','Whether key is a key',['Whether key is a value','Whether key is an index position','Whether all values equal key'],'Membership checks keys by default.','Understand membership'),
concept('What happens when an existing key is assigned a new value?','Its value is replaced',['A duplicate key entry is appended','The key is removed','All other values are replaced'],'Each key has one value.','Update entries'),
concept('What does items() supply when iterated?','Key-value pairs',['Only keys','Only values','Sorted indices'],'Each pair can be unpacked into two names.','Iterate entries'),
concept('Which is a valid dictionary key in these choices?','"name"',['[1, 2]','{"x": 1}','{1, 2}'],'Strings are hashable; these mutable collections are not.','Choose keys'),
output('d = {"x": 2}\nprint(d.get("y", 5))\nprint(len(d))','5\n1',['5\n2','None\n1','KeyError'],'Get returns the fallback without inserting y.','Trace get'),
output('d = {"x": 2, "x": 7}\nprint(d["x"])','7',['2','[2, 7]','KeyError'],'The later value replaces the earlier value for x.','Trace duplicate keys'),
output('d = {"a": 3}\nprint("a" in d)\nprint(3 in d)','True\nFalse',['True\nTrue','False\nTrue','False\nFalse'],'Both membership tests inspect keys.','Trace membership'),
output('d = {"a": 1, "b": 2}\nd["a"] = 9\nfor key in d:\n    print(key)','a\nb',['b\na','a\nb\na','1\n2'],'Updating a value does not move its key.','Trace insertion order'),
output('d = {"a": 4, "b": 6}\nprint(d.pop("a"))\nprint(len(d))','4\n1',['a\n1','4\n2','None\n1'],'Pop returns the removed value and decreases the entry count.','Trace pop'),
error('print({"a": 1}["b"])','KeyError',['IndexError','TypeError','ValueError'],'Indexing an absent key raises KeyError.','Identify absent key'),
error('d = {[1, 2]: "point"}','TypeError',['SyntaxError','KeyError','ValueError'],'Lists are unhashable and cannot be keys.','Identify invalid key'),
concept('A student expects len({"a": 1, "a": 2}) to be 2. What was missed?','Repeated keys keep only the last assigned value',['Dictionaries count only numeric keys','Len cannot count dictionaries','Every dictionary has one entry'],'There is only one distinct key a.','Avoid duplicate-key confusion',kind='mistake')
],[DATA,TYPES])

write_topic(14,"Functions","G5",["Define and call functions with parameters.","Distinguish returned values from printed output.","Use defaults and understand local names."],"""
## Name a reusable operation

`def` introduces a function name, parentheses and a colon. Its indented body runs when you call the function, not when the definition is first read. Parameters are names in the definition; arguments are values supplied by a call. Use descriptive names to make each function's purpose clear.

{{EX1}}

## Return a result

`return value` sends a value to the caller and ends that call immediately. Statements later in the same path are not reached. Printing displays text but does not return that text. A function with no return, or a bare return, returns None. Store a returned value or use it in another expression.

{{EX2}}

## Arguments and local names

Positional arguments match parameters in order. Keyword arguments name the parameter explicitly. A parameter can have a default, such as `name="friend"`; omitting that argument uses the default. In the ordinary parameter lists shown here, required positional parameters come before defaulted positional parameters. Use immutable defaults like numbers or strings here. A mutable default such as a list is created once at definition time and can accidentally share state between calls; avoid that pattern at this level.

Names assigned inside a function are local to that call unless you explicitly use more advanced scope features. A local assignment does not reassign an outer variable of the same name. Prefer parameters and return values instead of changing outside state.

{{EX3}}

## Common mistakes

- Forgetting parentheses when you intend to call a function.
- Expecting print to supply a useful returned result.
- Supplying too few required arguments or misspelling a keyword.
- Expecting a local variable to be available outside its function.

## Recap

Functions group reusable steps. Parameters receive arguments, return gives a result, and local names keep each call's work separate.

## Optional practice

Design a function that returns twice a number. Predict a call before checking it in local Python. Hunter System does not run submitted code.
""",[example('def double(number):\n    return number * 2\n\nprint(double(4))','8\n'),example('def greet():\n    print("Hello")\n\nresult = greet()\nprint(result)','Hello\nNone\n'),example('name = "Ada"\ndef greet(name="friend"):\n    return f"Hi {name}"\n\nprint(greet())\nprint(greet(name="Lin"))\nprint(name)','Hi friend\nHi Lin\nAda\n')],[
concept('When does a function body normally run?','When the function is called',['Whenever Python reads def, even without a call','Only when its name is printed','Only at program exit'],'Def creates the function; a call runs its body.','Understand definition and call'),
concept('What does return do?','Ends the current call and sends a value back',['Prints the value automatically','Ends every program','Repeats the function'],'The caller can use the returned value.','Understand return'),
concept('What is a parameter?','A name in a function definition that receives an argument',['Only a printed message','The function file name','An exception type'],'An argument is supplied at the call.','Distinguish parameters'),
concept('Why prefer a string rather than a list as a simple default?','A mutable default can retain changes across calls',['Strings cannot be passed as arguments','List defaults are syntax errors','Defaults are recreated on every call'],'Defaults are evaluated when the definition executes, not each call.','Choose safe defaults'),
output('def add(a, b):\n    return a + b\n\nprint(add(2, 5))','7',['25','None','2 5'],'The two arguments bind to a and b.','Trace arguments'),
output('def number():\n    return 3\n    print(9)\n\nprint(number())','3',['9\n3','3\n9','None'],'Return ends the call before print(9).','Trace early return'),
output('def greet(name="friend"):\n    return "Hi " + name\n\nprint(greet())','Hi friend',['Hi name','None','TypeError'],'The omitted argument uses the default.','Trace defaults'),
output('x = 4\ndef work():\n    x = 9\n    return x\n\nprint(work())\nprint(x)','9\n4',['9\n9','4\n4','None\n4'],'The local x and outer x are different bindings.','Trace local scope'),
output('def quiet():\n    pass\n\nprint(quiet())','None',['No output','0','False'],'Reaching the end without return yields None.','Trace implicit return'),
error('def add(a, b):\n    return a + b\n\nadd(1)','TypeError',['ValueError','NameError','SyntaxError'],'A required argument b is missing.','Identify missing argument'),
error('def work():\n    inside = 2\n\nwork()\nprint(inside)','NameError',['TypeError','ValueError','IndexError'],'Inside is local to work.','Identify inaccessible local'),
concept('A function only prints a total, but its caller needs to calculate with it. What should change?','Return the total',['Remove every parameter','Call print twice','Add pass after print'],'Return passes the numeric result to the caller.','Avoid print-return confusion',kind='mistake')
],[FLOW])

write_topic(15,"Modules and imports","G5",["Import standard-library modules and selected names.","Use aliases and module-qualified names.","Recognize a local module and avoid name shadowing."],"""
## Reuse code from a module

A module groups Python definitions in a file. Python includes a standard library, so many common tasks need no third-party installation. `import math` binds the module name math. Access a function with a dot, such as `math.sqrt(9)`. This computes a square root; the result is a float.

{{EX1}}

`from math import sqrt` binds sqrt directly in the current namespace. This does not bind the name math. `import math as m` gives the module a shorter local name m; use m.sqrt after that form. Choose one clear style and use the corresponding names consistently. `floor(x)` returns the greatest integer not above x; `ceil(x)` returns the least integer not below x. For example, floor(2.8) is 2 and ceil(2.2) is 3.

{{EX2}}

## Your own module

In a separate local Python project, a file named `helpers.py` can contain a function definition such as `def double(n):` with an indented `return n * 2`. Another file in that project can use `import helpers` and call `helpers.double(3)`. Do not name your own file math.py: it can shadow the standard module you intended to use. This is a local practice description, not an app upload or execution feature.

Module-level statements run when a module is first imported in an interpreter session. Repeated imports normally reuse the loaded module rather than rerunning every statement. A common guard, `if __name__ == "__main__":`, runs its body when the file is the main program rather than when imported. The special name is supplied by Python; it is not something you need to assign yourself. The next example imports factorial: for a positive whole number n, this multiplies the integers from 1 through n, so factorial(4) is 1 * 2 * 3 * 4.

{{EX3}}

## Common mistakes

- Calling sqrt without importing that name or qualifying it through math.
- Using math after importing it only as m.
- Naming a local file after a standard module.
- Using wildcard imports, which make it harder to know where names came from.

## Recap

Imports make reusable definitions available. The import form determines the names you can use. Keep module names clear and distinguish imported code from the main program.

## Optional practice

Plan a helpers.py file and a separate main file on paper. If you try them locally, keep them in a temporary study folder. No network access or package installation is needed.
""",[example('import math\nprint(math.sqrt(9))','3.0\n'),example('from math import floor\nimport math as m\nprint(floor(2.8))\nprint(m.ceil(2.2))','2\n3\n'),example('import math\nprint(math.factorial(4))','24\n')],[
concept('After import math, how do you access sqrt?','math.sqrt',['sqrt without qualification','import.sqrt','math["sqrt"]'],'Import math binds the module name.','Use qualified names'),
concept('What name does from math import sqrt bind directly?','sqrt',['math','import','All names from math'],'Only the requested name is bound by this statement.','Understand from import'),
concept('Why avoid naming a study file math.py?','It can shadow the intended standard-library module',['Python forbids all short file names','It changes every number into text','It automatically installs packages'],'Import resolution can find the local file instead of the library.','Avoid shadowing'),
concept('What is the purpose of the __main__ guard?','Run a block when the file is the main program',['Make every import rerun the block','Encrypt module contents','Create a new module account'],'Imported modules have their module name rather than __main__.','Understand main guard'),
output('import math\nprint(math.sqrt(16))','4.0',['4','8.0','16.0'],'Sqrt returns a floating-point square root.','Trace standard function'),
output('from math import floor\nprint(floor(-2.3))','-3',['-2','2','-2.3'],'Floor moves toward negative infinity.','Trace floor'),
output('import math as m\nprint(m.ceil(2.1))','3',['2','2.1','3.0'],'Ceil returns the least integer not below the argument.','Trace alias'),
output('from math import factorial\nprint(factorial(3))','6',['3','9','1'],'Factorial multiplies 3 by 2 by 1.','Trace imported function'),
output('import math\nroot = math.sqrt(25)\nprint(root + 1)','6.0',['6','26','5.0'],'The returned float 5.0 can be used in another expression.','Use module result'),
error('import math\nprint(sqrt(9))','NameError',['ImportError','TypeError','ValueError'],'Only math is bound; use math.sqrt.','Identify unqualified name'),
error('import math as m\nprint(math.sqrt(9))','NameError',['SyntaxError','ValueError','IndexError'],'The alias binds m, not math.','Identify alias misuse'),
concept('Why avoid from math import * in beginner code?','It hides which names were imported',['It always causes SyntaxError','It imports nothing','It requires a network connection'],'Explicit imports make dependencies and name conflicts clearer.','Avoid wildcard ambiguity',kind='mistake')
],[MODULES])

write_topic(16,"Exceptions","G5",["Handle expected exceptions with try and except.","Distinguish else and finally.","Raise an appropriate exception for invalid input."],"""
## Respond to a failed operation

An exception interrupts normal execution. A syntax error prevents code from being parsed; a runtime exception occurs when an operation fails while running. Put a risky operation inside try, followed by an except naming the expected exception. If that exception occurs, the remaining try statements are skipped and the matching handler runs. Execution can continue after the handler.

{{EX1}}

Catch specific exceptions so unrelated programming mistakes are not silently hidden. ValueError can mean text has the wrong numeric format; TypeError means an operation received an unsuitable type; ZeroDivisionError means division by zero. An unmatched exception propagates to the caller and can stop the program.

## Success and cleanup

A try statement can have else after its handlers. Else runs only if the try body finishes without an exception. Finally runs when leaving the try statement, whether its body succeeded or raised an exception. In normal beginner code it is useful for cleanup. Do not return from finally: it can hide a result or exception. A with block, taught next, handles file closure more clearly.

{{EX2}}

## Report invalid input deliberately

`raise ValueError("message")` creates an explicit failure. This helps functions refuse values they cannot meaningfully process. Error messages should be useful and should not reveal secrets. `except ValueError as error` binds the exception object; `str(error)` gives its message. Catch only when you can respond meaningfully instead of pretending the operation succeeded.

{{EX3}}

## Common mistakes

- Using a broad bare except to conceal every failure.
- Expecting statements after a failed operation inside try to run.
- Confusing else, which requires success, with finally, which handles leaving either way.
- Catching the wrong exception class.

## Recap

Try an operation, handle specific failures, use else for success-only work and finally for cleanup. Raise an exception when a function must reject invalid input.

## Optional practice

Trace conversion of "12" and "twelve" through a try/except on paper. This is optional practice, not code executed by Hunter System.
""",[example('try:\n    number = int("bad")\n    print(number)\nexcept ValueError:\n    print("Use digits")\nprint("done")','Use digits\ndone\n'),example('try:\n    print(int("4"))\nexcept ValueError:\n    print("invalid")\nelse:\n    print("success")\nfinally:\n    print("finished")','4\nsuccess\nfinished\n'),example('def check_age(age):\n    if age < 0:\n        raise ValueError("age must not be negative")\n    return age\n\ntry:\n    check_age(-1)\nexcept ValueError as error:\n    print(str(error))','age must not be negative\n')],[
concept('Why catch a specific exception class?','To handle expected failures without hiding unrelated bugs',['To prevent all syntax errors','To make the try body repeat forever','To disable exceptions globally'],'A narrow handler leaves unexpected failures visible.','Choose handlers'),
concept('When does try else run?','When the try body finishes without an exception',['Whenever except runs','Before the try body','Only after an unmatched exception'],'Else is the success path.','Understand else'),
concept('What does finally normally provide?','Cleanup when leaving the try statement',['A retry of the failed statement','Only success-only work','Automatic conversion to integers'],'Finally runs on success and on exception paths.','Understand finally'),
concept('Which statement explicitly signals an invalid value?','raise ValueError("invalid value")',['return ValueError alone','except ValueError alone','pass ValueError'],'Raise starts exception handling.','Raise exceptions'),
output('try:\n    print(int("x"))\nexcept ValueError:\n    print("invalid")','invalid',['x','0','None'],'Conversion fails and the handler prints.','Trace conversion handler'),
output('try:\n    print(6 // 2)\nexcept ZeroDivisionError:\n    print("zero")\nelse:\n    print("ok")','3\nok',['3\nzero','ok','zero'],'No exception occurs, so else follows the try output.','Trace success path'),
output('try:\n    print(1 / 0)\nexcept ZeroDivisionError:\n    print("handled")\nfinally:\n    print("end")','handled\nend',['end','handled','0\nend'],'The division fails; handler and finally both run.','Trace cleanup path'),
output('try:\n    int("bad")\n    print("after")\nexcept ValueError:\n    print("caught")\nprint("outside")','caught\noutside',['after\ncaught\noutside','outside','caught\nafter'],'The rest of the failing try block is skipped.','Trace skipped statements'),
output('try:\n    raise ValueError("too small")\nexcept ValueError as error:\n    print(str(error))','too small',['ValueError','None','error'],'The bound exception carries the supplied message.','Read exception message'),
error('try:\n    int("bad")\nexcept ZeroDivisionError:\n    print("handled")','ValueError',['ZeroDivisionError','TypeError','SyntaxError'],'The handler does not match the conversion failure.','Identify unmatched handler'),
error('raise ValueError("invalid")','ValueError',['NameError','TypeError','SyntaxError'],'An explicit unhandled raise stops execution.','Identify explicit failure'),
concept('A bare except silently ignores every failure. What improves it?','Catch the expected class and provide a meaningful response',['Add another pass','Remove all validation','Print credentials for debugging'],'Specific handling avoids concealing unrelated bugs and sensitive data.','Avoid silent failures',kind='mistake')
],[ERRORS])

write_topic(17,"File handling","G6",["Read and write UTF-8 text with with blocks.","Distinguish read, write and append modes.","Handle missing files and understand the read position."],"""
## Text files and safe practice

A file stores data beyond a variable's lifetime. `open(path, mode, encoding="utf-8")` opens a text file. Mode "r" reads an existing file, "w" writes and truncates an existing file, and "a" appends at the end, creating the file if needed. Never experiment with write mode on a file you need to keep.

The working examples here use the standard-library `tempfile.TemporaryDirectory` context manager. It provides a fresh temporary folder and cleans it up afterwards. The variable folder holds its path; joining it with "/note.txt" forms a path inside that disposable folder. No real document is overwritten. These snippets are intended for optional local study only.

{{EX1}}

## Close files reliably

`with open(...) as handle:` closes the file when the block ends, even if an exception occurs. `handle.write(text)` writes the supplied text and returns the number of characters written; it does not add a newline automatically. Include `"\\n"` if you want a line break. `read()` reads from the current position to the end. A second read at the end returns an empty string. `readline()` reads one line, usually including its newline. Iterating a text file visits lines.

{{EX2}}

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
""",[example('import tempfile\nwith tempfile.TemporaryDirectory() as folder:\n    path = folder + "/note.txt"\n    with open(path, "w", encoding="utf-8") as handle:\n        handle.write("Hello\\n")\n    with open(path, "r", encoding="utf-8") as handle:\n        print(handle.read(), end="")','Hello\n'),example('import tempfile\nwith tempfile.TemporaryDirectory() as folder:\n    path = folder + "/note.txt"\n    with open(path, "w", encoding="utf-8") as handle:\n        handle.write("A")\n    with open(path, "a", encoding="utf-8") as handle:\n        handle.write("B")\n    with open(path, "r", encoding="utf-8") as handle:\n        print(handle.read())\n        print(repr(handle.read()))','AB\n\'\'\n')],[
concept('Which mode can erase existing file contents when opened?','w',['r','a','No mode can erase text'],'Write mode truncates existing files; use only disposable files in practice.','Choose modes'),
concept('What does a with open block ensure?','The file is closed when the block exits',['The file can never be missing','The path is always absolute','All text is automatically printed'],'The context manager closes the handle even on exceptional exit.','Manage handles'),
concept('Does write("Hi") automatically add a newline?','No; include a newline explicitly when needed',['Yes, always','Only on Windows','Only with UTF-8'],'Write writes exactly the supplied text.','Understand write'),
concept('Relative file paths are interpreted from where?','The process current working directory',['Always the script directory','Always the desktop','Always the database directory'],'The working directory can differ from the script location.','Understand paths'),
output('with open("note.txt", "r", encoding="utf-8") as handle:\n    print(handle.read())','Hi',['Hi\nHi','None','2'],'Read returns the file text.', 'Read text',context='In an isolated temporary directory, note.txt contains exactly Hi (no newline).',files={'note.txt':'Hi'}),
output('with open("note.txt", "r", encoding="utf-8") as handle:\n    handle.read()\n    print(repr(handle.read()))',"''",['Hi','None','0'],'The first read reaches the end, so the second returns an empty string.','Trace position',context='The temporary file note.txt contains Hi.',files={'note.txt':'Hi'}),
output('with open("note.txt", "w", encoding="utf-8") as handle:\n    print(handle.write("cat"))','3',['cat','None','4'],'Write returns the number of characters written.','Read write result',context='note.txt is a disposable file in an isolated temporary directory.'),
output('with open("note.txt", "a", encoding="utf-8") as handle:\n    handle.write("B")\nwith open("note.txt", "r", encoding="utf-8") as handle:\n    print(handle.read())','AB',['B','A','A B'],'Append preserves A and adds B at the end.','Trace append',context='The temporary file note.txt initially contains A.',files={'note.txt':'A'}),
output('with open("note.txt", "r", encoding="utf-8") as handle:\n    for line in handle:\n        print(line, end="")','A\nB',['AB','A\n\nB','B\nA'],'Each line already carries a newline; end avoids adding another.','Iterate lines',context='The temporary file contains A followed by a newline, then B followed by a newline.',files={'note.txt':'A\nB\n'}),
error('with open("missing.txt", "r", encoding="utf-8") as handle:\n    print(handle.read())','FileNotFoundError',['ValueError','KeyError','SyntaxError'],'Read mode requires an existing file.','Identify missing file',context='The isolated temporary directory contains no missing.txt.'),
error('with open("note.txt", "r", encoding="utf-8") as handle:\n    text = handle.read()\nhandle.read()','ValueError',['FileNotFoundError','TypeError','IndexError'],'After the with block the handle is closed.','Identify closed handle',context='The temporary file note.txt contains Hi.',files={'note.txt':'Hi'}),
concept('Why is opening an important existing file with w unsafe for practice?','It truncates the existing contents immediately',['It only reads the first line','It encrypts the file','It always refuses existing files'],'Use disposable temporary files and choose modes deliberately.','Avoid accidental overwrite',kind='mistake')
],[IO,ERRORS])

write_topic(18,"Basic object-oriented programming","G6",["Define classes and create instances.","Initialize instance attributes with __init__.","Call methods and distinguish instance and class attributes."],"""
## Group data with behavior

A class defines a kind of object. An instance is one particular object created from that class. Use `class Name:` followed by an indented body. Calling the class creates an instance. A method is a function defined in a class. Its first parameter is conventionally self, which refers to the instance receiving the call. When you call `object.method()`, Python supplies that instance automatically.

{{EX1}}

## Initialize each instance

Python calls `__init__` to initialize a newly created instance. Other arguments supplied when calling the class are passed to this method after self. Assigning `self.name = name` stores an instance attribute; the parameter name alone is local to the method. Read an attribute with a dot, such as `student.name`. Each instance can have its own values.

{{EX2}}

Methods can read and change instance attributes or return a calculated result. Do not pass self manually when calling a bound method on an object. An initializer should not return a non-None value; its job is to initialize, not to replace the new object.

## Shared class attributes

An attribute assigned directly in the class body belongs to the class. Instances can read it if they have not defined their own attribute with that name. Use immutable class attributes for shared facts, such as a category label. Keep per-instance mutable data inside __init__: a list assigned in the class body can be shared unexpectedly by all instances. Inheritance and advanced object models are beyond this introductory lesson.

{{EX3}}

## Common mistakes

- Omitting self from a method definition.
- Assigning only to a local variable when you intend to store an attribute.
- Calling a method without the instance or supplying self twice.
- Putting a per-student mutable list in the class body.

## Recap

Classes describe objects; instances hold individual state. Initialize attributes through self and use methods to work with that state. Keep shared class facts distinct from instance data.

## Optional practice

Design a Book class with title and pages, and a method that returns a short description. Trace two independent instances on paper or in local Python. The app runs MCQs, not student code.
""",[example('class Greeter:\n    def hello(self):\n        return "Hello"\n\ngreeter = Greeter()\nprint(greeter.hello())','Hello\n'),example('class Student:\n    def __init__(self, name):\n        self.name = name\n\na = Student("Ada")\nb = Student("Lin")\nprint(a.name)\nprint(b.name)','Ada\nLin\n'),example('class Counter:\n    category = "practice"\n    def __init__(self):\n        self.value = 0\n    def add(self):\n        self.value += 1\n\na = Counter()\nb = Counter()\na.add()\nprint(a.value, b.value)\nprint(b.category)','1 0\npractice\n')],[
concept('What is an instance?','One object created from a class',['The class keyword itself','Only a module file','A function parameter default'],'A class can create many individual instances.','Distinguish instances'),
concept('What does self conventionally refer to in an instance method?','The instance receiving the call',['The entire Python interpreter','Always the class name string','The previous function result'],'Python supplies the instance for a bound method call.','Understand self'),
concept('What is the role of __init__?','Initialize a newly created instance',['Print every object automatically','Import all classes','Destroy all instances'],'It assigns starting state after instance creation.','Initialize state'),
concept('Where should a separate list for every instance normally be assigned?','To self.items inside __init__',['As items = [] in the class body','Only in an imported math module','As a default list argument shared by calls'],'Creating the list in each initializer gives each instance its own list.','Separate mutable state'),
output('class Box:\n    def __init__(self, size):\n        self.size = size\n\nprint(Box(4).size)','4',['None','size','Box'],'The initializer stores the argument on the new instance.','Trace initialization'),
output('class Number:\n    def __init__(self, value):\n        self.value = value\n    def double(self):\n        return self.value * 2\n\nprint(Number(3).double())','6',['3','None','9'],'The method reads the stored instance value.','Trace method result'),
output('class Box:\n    def __init__(self):\n        self.items = []\n\na = Box()\nb = Box()\na.items.append(1)\nprint(len(b.items))','0',['1','2','None'],'Each initializer creates a fresh list.','Trace independent state'),
output('class Tool:\n    category = "study"\n\nprint(Tool().category)','study',['Tool','None','category'],'The instance can read the class attribute.','Trace class attribute'),
output('class Counter:\n    def __init__(self):\n        self.value = 0\n    def add(self):\n        self.value += 1\n\nc = Counter()\nc.add()\nc.add()\nprint(c.value)','2',['0','1','None'],'Both calls update the same instance.','Trace state change'),
error('class Box:\n    pass\n\nprint(Box().missing)','AttributeError',['NameError','KeyError','IndexError'],'The instance and its class have no attribute named missing.','Identify absent attribute'),
error('class Box:\n    def __init__(self):\n        return 3\n\nBox()','TypeError',['ValueError','AttributeError','SyntaxError'],'An initializer must return None rather than another value.','Identify invalid initializer'),
concept('A method assigns name = "Ada", but obj.name is missing. What was omitted?','Store it as self.name',['Print name twice','Rename the class to name','Import name from math'],'A local name does not automatically become an instance attribute.','Avoid local-attribute confusion',kind='mistake')
],[CLASSES])

(ROOT / "manifest.json").write_text(json.dumps(dict(format_version=1, python_target="3.14", status="draft", topics=TOPICS),ensure_ascii=False,indent=2)+"\n",encoding="utf-8",newline="\n")
print(f"Authored {len(TOPICS)} lessons and {len(TOPICS)*12} questions; no lesson code executed.")
