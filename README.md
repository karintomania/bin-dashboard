# Bin Collection Dashboard

# Why
My council's bin collection schedule page is quite hard to use, so I just made my own version.

The council website requires me to type the street name and find my house every single time.

This is a painful process, especially on my phone.

<img width="970" height="890" alt="Gov_one" src="https://github.com/user-attachments/assets/9ef04703-27c9-499d-bd00-dfd85ad3a238" />

Mine looks more straightforward. It just shows which bin to put on the street.

<img width="970" height="890" alt="Screenshot from 2026-09-21 00-25-43" src="https://github.com/user-attachments/assets/2879fe50-c5d3-42c2-8eb7-d8dce49e5291" />


## Run

Add Configuration values (`BIN_TOKEN`, `BIN_ADDRESS_ID` and `BIN_URL`) in `compose.yaml` as environment variables.

Run the stack.
```
docker compose up
```

Then open http://localhost:8090/index.php.

Responses are cached in `cache.json` for 6 hours; delete the file to force a refresh.

## Run Tests
```
docker compose -f compose_test.yaml up
```
