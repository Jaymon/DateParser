# Date Parser

**NOTE** - This was Plancast's date parser, there's a small changelog in the comments that says this class is from August 2008, but in reality it predates that by years, that's just when I rewrote my original date parsing code from another project for Plancast.

I give you this history because this code is bad, it's really bad, I haven't ever released it because I've always been kind of embarassed by it. Now, the code works, pretty well actually, I'm just embarassed by the actual code, not the functionality of it.

For some reason, the other day, I decided I should pull this out and make it public, so here we are.


### How do I use it?

The `parser.php` file can be run on the command line so you can see how it works, basically:

```
$ php parser.php -f "oct 27, 2016" --field "november 1-25, 2017" --text "this is some text that has january 14, 2022 in it"
Start and stop values are unix timestamps.

november 1-25, 2017 -> start: 1509494400, stop: 1511654400
oct 27, 2016 -> start: 1477526400, stop: 1477612800
january 14, 2022 -> start: 1642118400, stop: 1642204800
```

Is all there is to it, the `--field` flag is for when you know you just have a date and time, the `--text` flag is for when you have a body of text that contains dates and you want to find all the dates that are in the text.

Basically, look at `parser.php` to see how to use it.

