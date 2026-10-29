# How it works

[← README](../README.md)

You don't need this page to build an app. It shows what happens between a key
press and the screen, for when you want to know.

## The update cycle

<picture>
  <source media="(max-width: 700px)" srcset="../diagrams/update-cycle-narrow.svg">
  <img width="720" src="../diagrams/update-cycle.svg"
       alt="A message goes to update, which returns a new immutable model and maybe a command. view draws the widgets of that model, Screen sends only the changed cells to the terminal, and what the reader does next becomes the next message.">
</picture>

Every message makes one trip around the ring. `update()` is the only step that
makes a new model. If it returns the same model, nothing is redrawn.

## Your app and the framework

<picture>
  <source media="(max-width: 700px)" srcset="../diagrams/runtime-narrow.svg">
  <img width="720" src="../diagrams/runtime.svg"
       alt="Program sits between the outside world and your app. It turns keys from the terminal into messages for your app, takes back a new model and a command, runs the command as a process or request, hands its result to your app as the next message, and sends only changed cells to the terminal.">
</picture>

Your app never touches the terminal, a process or the network. It returns a
command, which is plain data. `Program` runs it and sends the result back as a
message. See [Commands and effects](effects.md).

## One key press, start to finish

<picture>
  <source media="(max-width: 700px)" srcset="../diagrams/keystroke-narrow.svg">
  <img width="720" src="../diagrams/keystroke.svg"
       alt="Program reads the bytes of the up arrow from the terminal, InputParser turns them into a KeyMsg, and your app's update returns a new model and a command. Only if the model changed, your app's view draws the widgets and Screen sends the changed cells to the terminal.">
</picture>

The terminal sends ↑ as three bytes, `ESC [ A`. `InputParser` turns them into a
`KeyMsg`, and your `update()` answers it. A frame is drawn only when the model
changed, so a key nothing reacts to costs nothing.

## One turn of the event loop

<picture>
  <source media="(max-width: 700px)" srcset="../diagrams/program-tick-narrow.svg">
  <img width="720" src="../diagrams/program-tick.svg"
       alt="Woken by a key, a signal or a timer, Program reads the input, runs due timers and collects process output, then hands every queued message to update. If the program quit, start returns with the terminal restored. Otherwise it draws a frame only when one is due, and sleeps until the next event.">
</picture>

Everything that happened since the last turn becomes a message first. Only then
does your app see them, in order. At most one frame is drawn per turn, and never
more often than the frame rate allows (`Program::withFps()`).
